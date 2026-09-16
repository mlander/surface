<?php

declare(strict_types=1);

namespace Drupal\data_surface\Event;

use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched while a surface is still mutable, before it is advertised.
 *
 * Subscribers may widen: add namespaced third-party definitions, extend
 * choices, append refiners, register policy filters. This event is what
 * conserves form_alter's role — extensibility — at the definition layer,
 * where additions are advertised and validated instead of invisible.
 *
 * The builder here is the builder, not a copy of it and not a restricted
 * view of it: a subscriber is a contributor, and the rules that make
 * contributions safe are in the builder rather than in what it is handed
 * to. Which makes one convention load-bearing — **a subscriber passes
 * its own provider id to every method that takes a contributor**:
 *
 * @code
 * $event->builder->extendChoices('variant', ['ribbon' => $label], 'my_module');
 * $event->builder->addRefiner('variant', new MyVariantRefiner(), 'my_module');
 * @endcode
 *
 * That id is what the added values are recorded under, what the refiner
 * is handed at refinement time, and what a refusal names when two
 * modules contribute the same value. A subscriber that leaves it out is
 * claiming to be the surface's owner, and its refiner is then handed the
 * owner's values instead of its own.
 *
 * A subscriber picks the surfaces it cares about by host class, with
 * appliesTo(), or by host id, which is namespaced as
 * `<host type>:<id>`.
 *
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface::build()
 *   For the host id convention.
 * @see docs/refinement.md
 *   For the contribution model, with a worked example.
 */
final class DataSurfaceBuildEvent extends Event {

  /**
   * Constructs a DataSurfaceBuildEvent.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The mutable builder.
   * @param string $hostClass
   *   The class providing the surface.
   * @param string $hostId
   *   The namespaced identifier for the host, `<host type>:<id>`.
   */
  public function __construct(
    public readonly DataSurfaceBuilderInterface $builder,
    public readonly string $hostClass,
    public readonly string $hostId,
  ) {
  }

  /**
   * Tells whether the surface is being built for a given class.
   *
   * Asked with is_a() rather than by equality, so a subclass keeps what
   * its parent's extensions gave it. A block that extends another
   * module's block is still that block as far as a subscriber is
   * concerned, which is the same reading core gives an entity type's
   * class hierarchy; matching on equality silently dropped the
   * extension the moment anyone subclassed the host.
   *
   * @param string $class
   *   A class or interface name.
   *
   * @return bool
   *   TRUE when the host class is, or extends, or implements it.
   */
  public function appliesTo(string $class): bool {
    return is_a($this->hostClass, $class, TRUE);
  }

}
