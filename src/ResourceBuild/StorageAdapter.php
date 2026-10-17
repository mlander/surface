<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Entry;

/**
 * A resource's storage, as the pipeline's storage, composed along a tree.
 *
 * Both have three verbs, load, prepare and commit; a resource's storage
 * class is handed the context, the pipeline's storage the data surface.
 * The context is bound here, once, so the pipeline's submit() path
 * reaches the storage unchanged: load narrows what the storage returns
 * to the data surface's own keys; prepare hands the storage the accepted
 * values, in the shape a contributor's settings are stored in, and wraps
 * the array it rehearses in PreparedValues, so a dry run reports it and
 * a refusal from storage arrives as the pipeline's own violations;
 * commit hands the storage that same array back, with the context it
 * loads by.
 *
 * Storage composes along the tree of children. A child whose class
 * names storage of its own is routed to an adapter like this one, for
 * the child's data surface and in the child's context, so it loads its
 * own slice and commits it after its parent; a child without it is left
 * in the parent's values and stored by the parent under its key. A
 * slot routes by the variant its deciding key chooses, read from the
 * values themselves, which is why the routing lives here and not in a
 * CompositeStorage: a composite hands each child only the keys it claims,
 * and a slot's child needs its sibling to know which variant it is.
 *
 * A storage shape an alter hands the build for the keys it mounts
 * (HasStorageShapeInterface) is applied here, to that module's namespace
 * under `third_party_settings` and nothing else: fromStorage() on what
 * the storage loads, toStorage() in prepare. So the storage reads and
 * writes a contributor's settings in the shape they are stored in, and
 * never has to know the contributor exists, the way the engine's own
 * ConfigEntityStorage applies the same shape.
 *
 * @internal
 */
final class StorageAdapter implements DataSurfaceStorageInterface {

  use DependencySerializationTrait;

  /**
   * The route id of an attached child, which has one route only.
   */
  public const ATTACHED = '';

  /**
   * The input key a module's mounted settings live under.
   */
  public const THIRD_PARTY = 'third_party_settings';

  /**
   * Constructs a StorageAdapter.
   *
   * @param \Drupal\data_surface\Resource\ResourceStorageInterface $storage
   *   The resource's storage.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it loads and commits by.
   * @param array<string, array<string, \Drupal\data_surface\ResourceBuild\StorageAdapter>> $routes
   *   The children with storage of their own, keyed by the key they sit
   *   at, then by variant id; an attached child under ATTACHED.
   * @param string[] $identity
   *   The resource's identity keys, which a routed child learns from the
   *   accepted values when it is committed.
   * @param string[] $omitted
   *   The keys whose child the build left out for this caller, which the
   *   resource stores: what is stored under each is handed back to the
   *   storage untouched, because the caller could not see it.
   */
  public function __construct(
    protected ResourceStorageInterface $storage,
    protected OperationContext $context,
    protected array $routes = [],
    protected array $identity = [],
    protected array $omitted = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = self::shapeThirdParty($surface, array_intersect_key($this->storage->load($this->context), $surface->getDefinitions()->toArray()), FALSE);
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
   * The storage's own prepare(), over this level's own values in the
   * shape they are stored in, plus what it holds under a child the
   * caller could not see, then each routed child's, in the child's
   * context plus this level's identity, so a child of something being
   * created can rehearse against what it will belong to. Everything
   * storage refuses, at any level, is collected before anything is
   * thrown, and a child's violations are filed under the key it sits
   * at, the way the pipeline files a child's own.
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $own = self::shapeThirdParty($surface, $values, TRUE);
    if ($this->omitted !== []) {
      // Decision: see docs/decisions.md#omitted-is-omitted-both-ways.
      $stored = $this->storage->load($this->context);
      foreach ($this->omitted as $key) {
        if (array_key_exists($key, $stored)) {
          $own[$key] = $stored[$key];
        }
      }
    }
    $known = $this->knownWith($values);
    $children = [];
    $refused = [];
    foreach ($this->routes as $key => $routes) {
      $entry = $surface->getDefinitions()->entry($key);
      $id = $entry === NULL ? NULL : $this->routeOf($entry, $values);
      if ($id === NULL || !isset($routes[$id])) {
        // A variant without storage of its own: the parent stores it.
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
      catch (StorageViolationsException $e) {
        foreach ($e->getViolations() as $violation) {
          $refused[] = new Violation((string) $key, $violation->fullPath(), $violation->message, $violation->stale);
        }
      }
    }
    try {
      $artifact = $this->storage->prepare($this->context, $own);
    }
    catch (StorageViolationsException $e) {
      $refused = [...iterator_to_array($e->getViolations(), FALSE), ...$refused];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
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
        throw new \InvalidArgumentException('The prepared values did not come from this storage.');
      }
      $this->storage->commit($this->context, $artifact);
      return;
    }
    $own = is_array($artifact) ? ($artifact['own'] ?? NULL) : NULL;
    $children = is_array($artifact) ? ($artifact['children'] ?? NULL) : NULL;
    if (!is_array($own) || !is_array($children)) {
      throw new \InvalidArgumentException('The prepared values did not come from this storage.');
    }
    $known = $this->knownWith($prepared->values);
    $before = $after = [];
    foreach ($children as $key => [$id, $child_prepared]) {
      $route = $this->routes[$key][$id] ?? NULL;
      if ($route === NULL || !$child_prepared instanceof PreparedValues) {
        throw new \InvalidArgumentException('The prepared values did not come from this storage.');
      }
      // Decision: see docs/decisions.md#write-order.
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
    $this->storage->commit($this->context, $own);
    foreach ($after as [$route, $child_prepared]) {
      $route->withParentIdentity($known)->commit($child_prepared);
    }
  }

  /**
   * Gets what a prepare() rehearsed, as plain arrays, for a dry run.
   *
   * The storage's own prepared array, as it is, for a resource with no
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
      throw new \InvalidArgumentException('The prepared values did not come from this storage.');
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
   * Reads what a committed resource answers with, from its storage.
   *
   * The values of the declared outputs that the storage's load()
   * hands back, in the context the values were committed in: this
   * context plus the identity the values accepted, so a thing just
   * created is found by the identity it was given.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, whose output definitions say which keys are
   *   outputs.
   * @param array $values
   *   The accepted values.
   *
   * @return array
   *   The outputs the storage could say, keyed by output name; empty for
   *   a data surface that declares none.
   */
  public function outputs(DataSurfaceInterface $surface, array $values): array {
    $outputs = $surface->getOutputDefinitions()->toArray();
    if ($outputs === []) {
      return [];
    }
    $known = $this->knownWith($values);
    return array_intersect_key($this->storage->load($this->context->withKnown($known)->withId($this->context->id, FALSE)), $outputs);
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
   * Gets the context this storage loads and commits by.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The context.
   */
  public function getContext(): OperationContext {
    return $this->context;
  }

  /**
   * Picks the route a child's value takes.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The child's key.
   * @param array $values
   *   The values of its level, where a slot's deciding key is read.
   *
   * @return string|null
   *   ATTACHED for an attached child, the variant id for a slot, or NULL
   *   when the slot is unresolved.
   */
  protected function routeOf(Entry $entry, array $values): ?string {
    if ($entry->attachment !== NULL) {
      return self::ATTACHED;
    }
    return $entry->slot?->chosen($values[$entry->slot->by] ?? NULL);
  }

  /**
   * Gets the child's data surface one route writes for.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The child's key.
   * @param string $id
   *   The route.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The child.
   */
  protected static function childOf(Entry $entry, string $id): DataSurfaceInterface {
    return $entry->attachment !== NULL ? $entry->attachment->child : $entry->slot->variant($id)->child;
  }

  /**
   * Applies the data surface's storage shapes to mounted settings.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, which carries each contributor's storage shape.
   * @param array $values
   *   Values of its level.
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
