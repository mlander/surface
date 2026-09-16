<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\EventSubscriber;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_demo_extras\DemoExtrasVariantRefiner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Extends the demo formatter's surface from a third-party module.
 *
 * Both extension flavors, neither touching a form: a setting mounted
 * under this module's own namespace, and one more value contributed to a
 * key the formatter already owns, with a refiner registered beside it.
 * The provider id passed to both is what records the contribution as
 * this module's and what scopes the refiner to it.
 *
 * @see docs/refinement.md
 *   Where this module is the worked example.
 */
final class DemoExtrasSurfaceSubscriber implements EventSubscriberInterface {

  /**
   * This module's name, which is the id its contributions carry.
   */
  public const PROVIDER = 'data_surface_demo_extras';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [DataSurfaceBuildEvent::class => 'onSurfaceBuild'];
  }

  /**
   * Extends the demo formatter's surface.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The build event carrying the mutable builder.
   */
  public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
    // appliesTo(), not class equality: a formatter subclassing the demo
    // one keeps the extension rather than silently losing it.
    if (!$event->appliesTo(DataSurfaceDemoFormatter::class)) {
      return;
    }
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      'badge',
      DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Badge'))
        ->setDescription(new TranslatableMarkup('A badge rendered beside the value.'))
        ->addConstraint('LabeledChoice', [
          'choices' => [
            'star' => new TranslatableMarkup('Star'),
            'flame' => new TranslatableMarkup('Flame'),
          ],
        ]),
      'star',
    );
    $event->builder->extendChoices('variant', DemoExtrasVariantRefiner::choice(), self::PROVIDER);
    $event->builder->addRefiner('variant', new DemoExtrasVariantRefiner(), self::PROVIDER);
  }

}
