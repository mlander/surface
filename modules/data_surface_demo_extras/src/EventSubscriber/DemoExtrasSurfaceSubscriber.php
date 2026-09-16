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
 * Demonstrates both extension flavors, neither touching a form:
 * - A namespaced setting mounted under third_party_settings —
 *   advertised, validated, defaulted, and rendered in the generated form
 *   automatically, where the form-alter era gave only a form element no
 *   machine could see.
 * - A contribution to an existing key: one more variant added to what
 *   the formatter advertises, with a refiner registered under this
 *   module's name to say when that variant is offered.
 *
 * Both pass this module's provider id, which is the convention that
 * makes contributions safe: the id is what the value is recorded under,
 * what the refiner is handed at refinement time, and what a refusal
 * names if another module contributes the same value.
 *
 * The event fires while the surface is still mutable, so what every
 * consumer reads afterwards is the extended surface — never a form-only
 * variant of it.
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
    // Asked with appliesTo(), so a formatter subclassing the demo one
    // keeps the extension rather than silently losing it.
    if (!$event->appliesTo(DataSurfaceDemoFormatter::class)) {
      return;
    }
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      'badge',
      DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Badge'))
        ->setDescription(new TranslatableMarkup('A badge rendered beside the value.'))
        ->setRequired(FALSE)
        ->addConstraint('LabeledChoice', [
          'choices' => ['star', 'flame'],
          'labels' => [
            'star' => new TranslatableMarkup('Star'),
            'flame' => new TranslatableMarkup('Flame'),
          ],
        ]),
      'star',
    );
    // One more variant on a key this module does not own. It joins the
    // advertisement, so every consumer sees it — the select, the
    // validator, and any machine reading the contract — and the refiner
    // registered beside it decides when it is offered, speaking for this
    // value and no other.
    $event->builder->extendChoices('variant', DemoExtrasVariantRefiner::choice(), self::PROVIDER);
    $event->builder->addRefiner('variant', new DemoExtrasVariantRefiner(), self::PROVIDER);
  }

}
