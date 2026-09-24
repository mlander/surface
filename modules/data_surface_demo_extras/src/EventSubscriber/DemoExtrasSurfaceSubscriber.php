<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_demo_extras\DemoExtrasVariantRefiner;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_extras\ReviewDeadlineShape;
use Drupal\data_surface_demo_extras\Plugin\DataSurfaceWidget\CommaSeparatedListWidget;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Extends two surfaces this module does not own.
 *
 * The demo formatter's gets both extension flavors, neither touching a
 * form: a setting mounted under this module's own namespace, and one more
 * value contributed to a key the formatter already owns, with a refiner
 * registered beside it. The provider id passed to both is what records
 * the contribution as this module's and what scopes the refiner to it.
 *
 * The content type surface gets two editorial review settings, mounted
 * the same way. They are the surface half of a comparison: the same two
 * settings are added to core's own content type form by
 * NodeTypeFormHooks, the way a module without a surface would add them.
 *
 * @see docs/refinement.md
 *   Where this module is the worked example.
 * @see \Drupal\data_surface_demo_extras\Hook\NodeTypeFormHooks
 */
final class DemoExtrasSurfaceSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * This module's name, which is the id its contributions carry.
   */
  public const PROVIDER = 'data_surface_demo_extras';

  /**
   * Constructs a DemoExtrasSurfaceSubscriber.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which the labels this subscriber
   *   contributes are built through.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [DataSurfaceBuildEvent::class => 'onSurfaceBuild'];
  }

  /**
   * Extends whichever of this module's two surfaces is being built.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The build event carrying the mutable builder.
   */
  public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
    if ($event->hostId === NodeTypeReviewSettings::HOST_ID) {
      $this->extendNodeType($event);
      return;
    }
    // appliesTo(), not class equality: a formatter subclassing the demo
    // one keeps the extension rather than silently losing it.
    if (!$event->appliesTo(DataSurfaceDemoFormatter::class)) {
      return;
    }
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      'badge',
      DataDefinition::create('string')
        ->setLabel($this->t('Badge'))
        ->setDescription($this->t('A badge rendered beside the value.'))
        ->addConstraint('LabeledChoice', [
          'choices' => [
            'star' => $this->t('Star'),
            'flame' => $this->t('Flame'),
          ],
        ]),
      'star',
    );
    $event->builder->extendChoices('variant', DemoExtrasVariantRefiner::choice(), self::PROVIDER);
    $event->builder->addRefiner('variant', new DemoExtrasVariantRefiner(), self::PROVIDER);
  }

  /**
   * Mounts the editorial review settings on the content type surface.
   *
   * Everything the classic form alter keeps in form code is said here as
   * contract, where every caller of the surface reads it:
   *
   * - The deadline is asked for the way a person says it, an amount and
   *   a unit, and stored as the seconds the classic form stores. The
   *   conversion is a storage shape handed to the surface, which the
   *   target that writes third party settings applies to this module's
   *   namespace in prepare; the classic form does the same arithmetic in
   *   its entity builder, where nobody else can see it. The range is
   *   checked twice, and both gates hold: on the amount and unit a
   *   caller sent, in the caller's units, and on the stored seconds by
   *   the config schema's own Range when the target validates what it
   *   is about to write.
   * - The tags are a list of strings, which is the classic form's comma
   *   split said as a type: a caller sends a list and there is nothing to
   *   split. What the classic form then does to each tag — trims it,
   *   lower cases it, drops repeats — is said as what a stored tag looks
   *   like, a pattern on each item and uniqueness on the list, and a
   *   caller is refused rather than silently rewritten, because the
   *   pipeline never turns a value into a different one on a caller's
   *   behalf.
   *
   * Matched by host id, so this module needs nothing from the module
   * that provides the content type surface. Where either value is stored
   * is not this module's business: the provider's target already routes
   * the third party mount to the node type's own third party settings.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The build event carrying the mutable builder.
   */
  protected function extendNodeType(DataSurfaceBuildEvent $event): void {
    $unit = DataDefinition::create('string')
      ->setLabel($this->t('Unit'))
      ->addConstraint('LabeledChoice', [
        'choices' => array_keys(NodeTypeReviewSettings::UNITS),
        'labels' => [
          'hours' => $this->t('Hours'),
          'days' => $this->t('Days'),
          'weeks' => $this->t('Weeks'),
        ],
      ]);
    DefinitionMetadata::setDefaultValue($unit, NodeTypeReviewSettings::DEFAULT_UNIT);
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      NodeTypeReviewSettings::DEADLINE,
      MapDataDefinition::create()
        ->setLabel($this->t('Review deadline'))
        ->setDescription($this->t('How long an editor has to review new content of this type, from one hour to thirty days. Leave the amount empty for no deadline.'))
        ->setPropertyDefinition(NodeTypeReviewSettings::AMOUNT, DataDefinition::create('integer')
          ->setLabel($this->t('Amount'))
          ->addConstraint('Range', ['min' => 1]))
        ->setPropertyDefinition(NodeTypeReviewSettings::UNIT, $unit)
        ->addConstraint('DataSurfaceDemoExtrasReviewDeadline', []),
    );
    $event->builder->setThirdPartyShape(self::PROVIDER, new ReviewDeadlineShape());
    $tag = DataDefinition::create('string')
      ->setLabel($this->t('Audience tag'))
      ->addConstraint('Regex', [
        'pattern' => NodeTypeReviewSettings::TAG_PATTERN,
        'message' => 'An audience tag is lower case letters and digits, words joined by one space or one hyphen, with nothing around it.',
      ]);
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      NodeTypeReviewSettings::TAGS,
      (new ListDataDefinition([], $tag))
        ->setLabel($this->t('Audience tags'))
        ->setDescription($this->t('Who content of this type is written for, each tag listed once and in lower case, for example "local news" or "sports".'))
        ->setSetting(CommaSeparatedListWidget::SETTING, TRUE)
        ->addConstraint('DataSurfaceDemoExtrasUniqueItems', []),
      [],
    );
  }

}
