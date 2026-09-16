<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Target\CompositeTarget;

/**
 * The destination of an add, which the submission itself names.
 *
 * The one thing the provider contract's target accessor cannot answer
 * directly, and it is worth saying why rather than working around it
 * quietly. A subject is the id of a thing that exists, so an add
 * operation has none: there is nothing yet to name. But a content
 * type's base field overrides belong to a bundle, and the bundle IS
 * the machine name being submitted — so the destination of an add is
 * known one stage later than the destination of an edit.
 *
 * This target is that one stage of patience. It carries no state of its
 * own beyond the composite it has resolved: the read path measures
 * against the entity type's own base fields, exactly as a brand new
 * bundle does, and the write path builds the real composite around the
 * machine name the values carry.
 *
 * The alternative, a target accessor taking the accepted values, would
 * have put a write-path argument on a method the discovery endpoint
 * calls with nothing but a coordinate.
 *
 * @see \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider::getDataSurfaceTarget()
 * @see \Drupal\data_surface\Target\BaseFieldOverrideTarget
 */
final class NodeTypeAddTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The composite resolved so far, and the bundle it was resolved for.
   *
   * Memoized so that the target which prepared an artifact is the one
   * that commits it, which is what lets the base field override half
   * drop its stale field definitions after the bundle it just created
   * exists.
   *
   * @var array{string, \Drupal\data_surface\Target\CompositeTarget}|null
   */
  protected ?array $resolved = NULL;

  /**
   * Constructs a NodeTypeAddTarget.
   *
   * @param \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider $provider
   *   The provider, which is where the composite is built.
   */
  public function __construct(
    protected readonly NodeTypeSurfaceProvider $provider,
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * Nothing is stored yet, so what this reads is what a content type
   * that does not exist starts from: an unsaved node type entity's own
   * property defaults, and the node base fields before any bundle has
   * overridden them. Which is to say the same values the add surface
   * declares — asserted rather than assumed, in the kernel test.
   */
  public function load(DataSurfaceInterface $surface): array {
    return $this->composite('')->load($surface);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    return $this->composite($this->bundleOf($values))->prepare($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $this->composite($this->bundleOf($prepared->values))->commit($prepared);
  }

  /**
   * Reads the machine name the submission gives the new content type.
   *
   * @param array $values
   *   The accepted values.
   *
   * @return string
   *   The bundle, or the empty string when the values name none, which
   *   the surface's own required machine name has already refused by
   *   the time anything is prepared.
   */
  protected function bundleOf(array $values): string {
    return (string) ($values['type'] ?? '');
  }

  /**
   * Gets the composite target for one bundle.
   *
   * @param string $bundle
   *   The machine name the overrides belong to.
   *
   * @return \Drupal\data_surface\Target\CompositeTarget
   *   The node type entity and its base field overrides, in that order.
   */
  protected function composite(string $bundle): CompositeTarget {
    if ($this->resolved === NULL || $this->resolved[0] !== $bundle) {
      $this->resolved = [$bundle, $this->provider->targetFor(NULL, $bundle)];
    }
    return $this->resolved[1];
  }

}
