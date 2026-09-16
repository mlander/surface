<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds surfaces through the alter stage.
 *
 * Building is the factory's, not the attribute's: the attribute declares
 * what a class's surface holds and nothing more, so reading a
 * declaration costs no container and building one always goes through
 * the same alter stage a hand-built surface goes through.
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

  /**
   * {@inheritdoc}
   */
  public function buildFromClass(string $class, ?DataSurfaceRefinerInterface $refiner, string $host_id, ?callable $before_seal = NULL): DataSurfaceInterface {
    $attribute = DataSurfaceAware::fromClass($class);
    if ($attribute === NULL || $attribute->definitions === []) {
      throw new \LogicException(sprintf(
        '%s declares no static surface definitions; build the surface in getDataSurface() instead.',
        $class,
      ));
    }
    // The outputs travel through the builder's constructor like the
    // inputs do, rather than through a loop of setters, so a declaration
    // that refuses — a default on an output — refuses at seal with the
    // whole picture rather than halfway through the harvest.
    $builder = new DataSurfaceBuilder(
      $attribute->definitions,
      $attribute->refinements,
      $refiner,
      $attribute->outputs,
      $attribute->output_refinements,
    );
    foreach ($attribute->locked as $name) {
      $builder->lock($name);
    }
    if ($before_seal !== NULL) {
      $before_seal($builder);
    }
    return $this->build($builder, $class, $host_id);
  }

}
