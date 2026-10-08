<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\SurfaceViolation;
use Drupal\data_surface\Pipeline\TargetViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\SurfaceEntry;

/**
 * A new-spelling target, as the pipeline's target, composed along a tree.
 *
 * Both have three verbs, load, prepare and commit; the sketch's target
 * is handed the context, the pipeline's the surface. The context is
 * bound here, once, so the pipeline's submit() path reaches the target
 * unchanged: load narrows what the target returns to the surface's own
 * keys; prepare hands the target the accepted values, in the shape a
 * contributor's settings are stored in, and wraps the array it rehearses
 * in PreparedValues, so a dry run reports it and a refusal from storage
 * arrives as the pipeline's own violations; commit hands the target that
 * same array back, with the context it loads by.
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
 * A storage shape an alter hands the surface for the keys it mounts
 * (HasStorageShapeInterface) is applied here, to that module's namespace
 * under `third_party_settings` and nothing else: fromStorage() on what
 * the target loads, toStorage() in prepare. So the target reads and
 * writes a contributor's settings in the shape they are stored in, and
 * never has to know the contributor exists, the way the engine's own
 * ConfigEntityTarget applies the same shape.
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
   * The surface key a module's mounted settings live under.
   */
  public const THIRD_PARTY = 'third_party_settings';

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
    $values = self::shapeThirdParty($surface, array_intersect_key($this->target->load($this->context), $surface->getDefinitions()->toArray()), FALSE);
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
   *
   * The target's own prepare(), over this surface's own values in the
   * shape they are stored in, then each routed child's, in the child's
   * context plus this level's identity, so a child of something being
   * created can rehearse against what it will belong to. Everything
   * storage refuses, at any level, is collected before anything is
   * thrown, and a child's violations are filed under the key it sits
   * at, the way the pipeline files a child's own.
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $own = self::shapeThirdParty($surface, $values, TRUE);
    $known = $this->knownWith($values);
    $children = [];
    $refused = [];
    foreach ($this->routes as $key => $routes) {
      $entry = $surface->getDefinitions()->entry($key);
      $id = $entry === NULL ? NULL : $this->routeOf($entry, $values);
      if ($id === NULL || !isset($routes[$id])) {
        // A variant without a target of its own: the parent stores it.
        continue;
      }
      unset($own[$key]);
      if (!is_array($values[$key] ?? NULL)) {
        continue;
      }
      try {
        $route = $routes[$id]->withParentIdentity($known);
        $children[$key] = [$id, $route->prepare(self::childOf($entry, $id), $values[$key])];
      }
      catch (TargetViolationsException $e) {
        foreach ($e->getViolations() as $violation) {
          $refused[] = new SurfaceViolation((string) $key, $violation->fullPath(), $violation->message, $violation->stale);
        }
      }
    }
    try {
      $artifact = $this->target->prepare($this->context, $own);
    }
    catch (TargetViolationsException $e) {
      $refused = [...iterator_to_array($e->getViolations(), FALSE), ...$refused];
    }
    if ($refused !== []) {
      throw new TargetViolationsException(new ViolationSet($refused));
    }
    assert(isset($artifact));
    return new PreparedValues($values, $this->routes === [] ? $artifact : ['own' => $artifact, 'children' => $children]);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $artifact = $prepared->artifact;
    if ($this->routes === []) {
      if (!is_array($artifact)) {
        throw new \InvalidArgumentException('The prepared values did not come from this target.');
      }
      $this->target->commit($this->context, $artifact);
      return;
    }
    $own = is_array($artifact) ? ($artifact['own'] ?? NULL) : NULL;
    $children = is_array($artifact) ? ($artifact['children'] ?? NULL) : NULL;
    if (!is_array($own) || !is_array($children)) {
      throw new \InvalidArgumentException('The prepared values did not come from this target.');
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's child target loads by identity its context knows, but a creating parent's identity is only known once accepted; a routed child is prepared and committed in its context plus the parent's accepted identity values it does not already know.
    $known = $this->knownWith($prepared->values);
    $before = $after = [];
    foreach ($children as $key => [$id, $child_prepared]) {
      $route = $this->routes[$key][$id] ?? NULL;
      if ($route === NULL || !$child_prepared instanceof PreparedValues) {
        throw new \InvalidArgumentException('The prepared values did not come from this target.');
      }
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch does not say in which order a parent and the children stored apart from it are written; an attached child is a part the parent is built on (a field's storage) and is committed before it, a slot's variant lives on what the parent writes (a field's settings) and is committed after it.
      if ($id === self::ATTACHED) {
        $before[] = [$route, $child_prepared];
      }
      else {
        $after[] = [$route, $child_prepared];
      }
    }
    foreach ($before as [$route, $child_prepared]) {
      $route->withParentIdentity($known)->commit($child_prepared);
    }
    $this->target->commit($this->context, $own);
    foreach ($after as [$route, $child_prepared]) {
      $route->withParentIdentity($known)->commit($child_prepared);
    }
  }

  /**
   * Gets what a prepare() rehearsed, as plain arrays, for a dry run.
   *
   * The target's own prepared array, as it is, for a surface with no
   * routed children. Otherwise the parent's under `own` and each routed
   * child's under `children`, by the key it sits at, recursively: what
   * each storage would be handed, nothing of how the pipeline carried it.
   *
   * @param \Drupal\data_surface\Pipeline\PreparedValues $prepared
   *   What prepare() returned.
   *
   * @return array
   *   The rehearsed storage shapes.
   */
  public function preview(PreparedValues $prepared): array {
    $artifact = $prepared->artifact;
    if (!is_array($artifact)) {
      throw new \InvalidArgumentException('The prepared values did not come from this target.');
    }
    if ($this->routes === []) {
      return $artifact;
    }
    $children = [];
    foreach ($artifact['children'] ?? [] as $key => [$id, $child_prepared]) {
      $route = $this->routes[$key][$id] ?? NULL;
      if ($route !== NULL && $child_prepared instanceof PreparedValues) {
        $children[$key] = $route->preview($child_prepared);
      }
    }
    return ['own' => $artifact['own'] ?? [], 'children' => $children];
  }

  /**
   * Gets this context's known identity plus the identity values accepted.
   *
   * @param array $values
   *   The accepted values of this level.
   *
   * @return array<string, mixed>
   *   The identity a routed child is handed; what the context already
   *   knows wins.
   */
  protected function knownWith(array $values): array {
    return array_intersect_key($values, array_flip($this->identity)) + $this->context->known;
  }

  /**
   * Reads what a committed surface answers with, from its target.
   *
   * The values of the surface's declared outputs that its target's load()
   * hands back, in the context the values were committed in: this
   * context plus the identity the values accepted, so a thing just
   * created is found by the identity it was given.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, whose output definitions say which keys are outputs.
   * @param array $values
   *   The accepted values.
   *
   * @return array
   *   The outputs the target could say, keyed by output name; empty for a
   *   surface that declares none.
   */
  public function outputs(DataSurfaceInterface $surface, array $values): array {
    $outputs = $surface->getOutputDefinitions()->toArray();
    if ($outputs === []) {
      return [];
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's surface declares outputs but not who produces their values; they are what the target's load() hands back under an output's name, once the values are committed.
    $known = $this->knownWith($values);
    return array_intersect_key($this->target->load($this->context->withKnown($known)->withOperation($this->context->operation, FALSE)), $outputs);
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

  /**
   * Applies the storage shapes the surface carries to mounted settings.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, which carries each contributor's storage shape.
   * @param array $values
   *   Values of this surface's level.
   * @param bool $to_storage
   *   TRUE to turn them into the stored shape, FALSE to read them back.
   *
   * @return array
   *   The values, each shaped module's settings translated and nothing
   *   else touched.
   */
  protected static function shapeThirdParty(DataSurfaceInterface $surface, array $values, bool $to_storage): array {
    if (!is_array($values[self::THIRD_PARTY] ?? NULL)) {
      return $values;
    }
    foreach ($values[self::THIRD_PARTY] as $module => $settings) {
      $shape = $surface->getThirdPartyShape((string) $module);
      if ($shape !== NULL && is_array($settings)) {
        $values[self::THIRD_PARTY][$module] = $to_storage ? $shape->toStorage($settings) : $shape->fromStorage($settings);
      }
    }
    return $values;
  }

}
