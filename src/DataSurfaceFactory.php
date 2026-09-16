<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds surfaces through the alter stage.
 *
 * Building is the factory's, and only the factory's: a class says what
 * its surface holds, which costs no container to read, and turning that
 * into a sealed, altered surface goes through the one alter stage here,
 * whether the declaration was written in a method or handed over as a
 * builder somebody else filled.
 *
 * Host ids are namespaced `<host type>:<id>` — `block:foo`,
 * `field_formatter:foo`, `field_type:address`, `entity_type:node_type` —
 * so a subscriber matching on the id cannot pick up a host of another
 * kind that shares a name. The convention is the factory's because the
 * factory is what every host routes through.
 *
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface
 *   For the documentation of every method, and for the convention.
 */
final class DataSurfaceFactory implements DataSurfaceFactoryInterface {

  /**
   * Constructs a DataSurfaceFactory.
   *
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher, which offers the builder to subscribers
   *   while it is still mutable.
   */
  public function __construct(
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(DataSurfaceBuilderInterface $builder, string $host_class, string $host_id): DataSurfaceInterface {
    if ($builder->isSealed()) {
      throw new \LogicException(sprintf(
        'The builder for %s has already been sealed: a builder advertises one surface and dispatches one build event, so build a fresh one rather than reusing it.',
        $host_id,
      ));
    }
    $this->eventDispatcher->dispatch(new DataSurfaceBuildEvent($builder, $host_class, $host_id));
    return $builder->seal();
  }

}
