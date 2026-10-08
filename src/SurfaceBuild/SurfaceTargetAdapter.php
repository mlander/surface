<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\SurfaceEntry;

/**
 * A new-spelling target, as the pipeline's target, composed along a tree.
 *
 * The sketch's target has two verbs, load and commit, and is handed the
 * context; the pipeline's has three, load, prepare and commit, and is
 * handed the surface. The context is bound here, once, so the pipeline's
 * submit() path reaches the target unchanged: load narrows what the
 * target returns to the surface's own keys, prepare is the identity —
 * the sketch's target writes canonical values and has no storage shape
 * of its own to translate to — and commit hands the accepted values to
 * the target with the context it loads by.
 *
 * Targets compose along the subsurface tree. A subsurface whose class
 * names a target of its own is routed to an adapter like this one, for
 * the child surface and in the child's context, so it loads its own
 * slice and commits it after its parent; a subsurface without one is
 * left in the parent's values and stored by the parent under its key. A
 * slot routes by the variant its deciding key chooses, read from the
 * values themselves, which is why the routing lives here and not in a
 * CompositeTarget: a composite hands each child only the keys it claims,
 * and a slot's child needs its sibling to know which variant it is.
 *
 * A storage shape for third-party settings, which the engine's own
 * targets apply in prepare, is not applied here: no surface in the new
 * spelling has a target yet that stores them.
 *
 * @internal
 */
final class SurfaceTargetAdapter implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The route id of an attached child, which has one route only.
   */
  public const ATTACHED = '';

  /**
   * Constructs a SurfaceTargetAdapter.
   *
   * @param \Drupal\data_surface\Surface\SurfaceTargetInterface $target
   *   The surface's target.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context it loads and commits by.
   * @param array<string, array<string, \Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter>> $routes
   *   The children with targets of their own, keyed by the key they sit
   *   at, then by variant id; an attached child under ATTACHED.
   * @param string[] $identity
   *   The surface's identity keys, which a routed child learns from the
   *   accepted values when it is committed.
   */
  public function __construct(
    protected SurfaceTargetInterface $target,
    protected SurfaceContext $context,
    protected array $routes = [],
    protected array $identity = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = array_intersect_key($this->target->load($this->context), $surface->getDefinitions()->toArray());
    foreach ($this->routes as $key => $routes) {
      $entry = $surface->getDefinitions()->entry($key);
      $id = $entry === NULL ? NULL : $this->routeOf($entry, $values + $surface->getDefaultValues());
      if ($id !== NULL && isset($routes[$id])) {
        // Nothing stored apart is nothing stored: the key is left out, so
        // it starts from the child's defaults like any key storage lacks.
        $loaded = $routes[$id]->load(self::childOf($entry, $id));
        if ($loaded !== []) {
          $values[$key] = $loaded;
        }
        else {
          unset($values[$key]);
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    if ($this->routes === []) {
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch's target has no prepare step and no third-party storage shape; prepare is the identity and mounted settings shapes are not applied.
      return new PreparedValues($values, $values);
    }
    $own = $values;
    $children = [];
    foreach ($this->routes as $key => $routes) {
      $entry = $surface->getDefinitions()->entry($key);
      $id = $entry === NULL ? NULL : $this->routeOf($entry, $values);
      if ($id === NULL || !isset($routes[$id])) {
        // A variant without a target of its own: the parent stores it.
        continue;
      }
      unset($own[$key]);
      if (is_array($values[$key] ?? NULL)) {
        $children[$key] = [$id, $routes[$id]->prepare(self::childOf($entry, $id), $values[$key])];
      }
    }
    return new PreparedValues($values, ['own' => $own, 'children' => $children]);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    if ($this->routes === []) {
      $this->target->commit($this->context, $prepared->values);
      return;
    }
    $artifact = $prepared->artifact;
    if (!is_array($artifact) || !isset($artifact['own'], $artifact['children'])) {
      throw new \InvalidArgumentException('The prepared values did not come from this target.');
    }
    // The parent first: a child stored apart from it is usually stored on
    // the thing the parent creates.
    $this->target->commit($this->context, $artifact['own']);
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's child target loads by identity its context knows, but a creating parent's identity is only known once accepted; a routed child is committed in its context plus the parent's accepted identity values it does not already know.
    $known = array_intersect_key($prepared->values, array_flip($this->identity)) + $this->context->known;
    foreach ($artifact['children'] as $key => [$id, $child_prepared]) {
      $route = $this->routes[$key][$id] ?? NULL;
      if ($route === NULL || !$child_prepared instanceof PreparedValues) {
        throw new \InvalidArgumentException('The prepared values did not come from this target.');
      }
      $route->withParentIdentity($known)->commit($child_prepared);
    }
  }

  /**
   * Gets a copy whose context also knows its parent's identity.
   *
   * @param array<string, mixed> $known
   *   The parent's identity values; what this context already knows
   *   wins.
   *
   * @return static
   *   The copy.
   */
  public function withParentIdentity(array $known): static {
    $copy = clone $this;
    $copy->context = $this->context->withKnown(array_diff_key($known, $this->context->known));
    return $copy;
  }

  /**
   * Gets the context this target loads and commits by.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The context.
   */
  public function getContext(): SurfaceContext {
    return $this->context;
  }

  /**
   * Picks the route a subsurface's value takes.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The subsurface key.
   * @param array $values
   *   The values of its level, where a slot's deciding key is read.
   *
   * @return string|null
   *   ATTACHED for an attached child, the variant id for a slot, or NULL
   *   when the slot is unresolved.
   */
  protected function routeOf(SurfaceEntry $entry, array $values): ?string {
    if ($entry->attachment !== NULL) {
      return self::ATTACHED;
    }
    return $entry->slot?->chosen($values[$entry->slot->by] ?? NULL);
  }

  /**
   * Gets the child surface one route writes for.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The subsurface key.
   * @param string $id
   *   The route.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The child.
   */
  protected static function childOf(SurfaceEntry $entry, string $id): DataSurfaceInterface {
    return $entry->attachment !== NULL ? $entry->attachment->child : $entry->slot->variant($id)->child;
  }

}
