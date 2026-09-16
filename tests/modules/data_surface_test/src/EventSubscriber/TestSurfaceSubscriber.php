<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface_test\RibbonVariantRefiner;
use Drupal\data_surface_test\VariantPolicyFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Extends surfaces built for the hosts the kernel tests exercise.
 *
 * Demonstrates every extension flavor: a namespaced third-party setting
 * mounted with a default, a value contributed to an existing key with a
 * refiner registered under this module's name, and — on request, so the
 * demo formatter's own behavior stays readable — a policy filter.
 */
final class TestSurfaceSubscriber implements EventSubscriberInterface {

  /**
   * This module's name, which is the id its contributions carry.
   */
  public const PROVIDER = 'data_surface_test';

  /**
   * The state key naming the policy filter to register, if any.
   */
  public const FILTER_STATE = 'data_surface_test.filter';

  /**
   * The host id whose outputs this subscriber contributes to.
   */
  public const OUTPUT_HOST_ID = 'test:data_surface_output';

  /**
   * Constructs a TestSurfaceSubscriber.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Site state, which is how a test says which policy filter this
   *   subscriber should register before it builds a surface.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [DataSurfaceBuildEvent::class => 'onSurfaceBuild'];
  }

  /**
   * Extends the test surface.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The build event carrying the mutable builder.
   */
  public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
    $policy = $this->state->get(self::FILTER_STATE);
    if (is_array($policy)) {
      $event->builder->addFilter(new VariantPolicyFilter($policy['key'], $policy['remove'], $policy['add'] ?? []));
    }
    if ($event->hostId === self::OUTPUT_HOST_ID) {
      // The output side of contributing: a module may add to what a host
      // emits, under its own namespace, and never touch what the host
      // itself said it emits.
      $event->builder->setThirdPartyOutputDefinition(
        self::PROVIDER,
        'badge',
        DataDefinition::create('string')
          ->setLabel(new TranslatableMarkup('Badge'))
          ->addConstraint('Choice', ['choices' => ['star', 'flame']]),
      );
      return;
    }
    if ($event->hostId !== 'test:data_surface_test') {
      return;
    }
    $event->builder->setThirdPartyDefinition(
      self::PROVIDER,
      'badge',
      DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Badge'))
        ->addConstraint('Choice', ['choices' => ['star', 'flame']]),
      'star',
    );
    if ($event->builder->getDefinition('variant') !== NULL) {
      $event->builder->extendChoices('variant', RibbonVariantRefiner::CHOICE, self::PROVIDER);
      $event->builder->addRefiner('variant', new RibbonVariantRefiner(), self::PROVIDER);
    }
  }

}
