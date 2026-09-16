<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Sends one surface's values to several destinations in order.
 *
 * Some things store in more than one place and still store in one
 * operation: a node type writes its own entity plus a couple of base
 * field overrides, and the whole lot is one submit, one surface, one
 * commit. This target is how that stays true. Each child receives the
 * keys it was given, and the children run in the order they were listed,
 * which is the order a caller needs when a later write depends on an
 * earlier one having happened.
 *
 * The one rule, and the reason the class validates rather than trusting
 * the caller: every surface key belongs to exactly one child. A key no
 * child claims would be accepted, validated, reported committed and
 * never written, and a key two children claim would be written twice, to
 * two destinations that then disagree about it the moment either is
 * edited on its own. Both are silent, so both are refused. A child
 * listed without keys is the shorthand for "this child owns everything",
 * which is the same rule with one child.
 *
 * The check needs the surface, which the constructor does not have, so
 * it runs at the first call that does: load() on the read path, and
 * prepare() for a caller that only writes. Either way it runs before
 * anything is shaped, let alone stored.
 *
 * The artifact is the list of the children's own prepared values, so a
 * preview can show every destination separately, and commit hands each
 * child back exactly what it prepared.
 *
 * @see docs/targets.md
 */
final class CompositeTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The children, each as a target and the keys it receives.
   *
   * @var array<int, array{0: \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface, 1: string[]|null}>
   */
  protected readonly array $children;

  /**
   * Constructs a CompositeTarget.
   *
   * @param array $children
   *   An ordered list of children. Each is either a target on its own,
   *   meaning it receives every surface key, or a pair of a target and
   *   the list of surface keys it receives. Across the whole list every
   *   surface key must be claimed exactly once.
   */
  public function __construct(array $children) {
    $pairs = [];
    foreach ($children as $child) {
      if ($child instanceof DataSurfaceTargetInterface) {
        $pairs[] = [$child, NULL];
        continue;
      }
      if (!is_array($child) || !($child[0] ?? NULL) instanceof DataSurfaceTargetInterface) {
        throw new \InvalidArgumentException('A composite target child must be a target, or a target and the surface keys it receives.');
      }
      $keys = $child[1] ?? NULL;
      $pairs[] = [$child[0], $keys === NULL ? NULL : array_values($keys)];
    }
    $this->children = $pairs;
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $this->checkClaims($surface);
    $values = [];
    foreach ($this->children as [$target, $keys]) {
      $loaded = $target->load($surface);
      if ($keys !== NULL) {
        $loaded = array_intersect_key($loaded, array_flip($keys));
      }
      // A union rather than an overwrite: the keys are divided between
      // the children, so nothing collides, and a child that volunteers
      // more than it was asked for cannot speak for another's key.
      $values += $loaded;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $this->checkClaims($surface);
    $artifacts = [];
    $dependencies = [];
    foreach ($this->children as [$target, $keys]) {
      $subset = $keys === NULL ? $values : array_intersect_key($values, array_flip($keys));
      $prepared = $target->prepare($surface, $subset);
      $artifacts[] = $prepared;
      foreach ($prepared->dependencies as $type => $names) {
        $dependencies[$type] = array_values(array_unique(array_merge($dependencies[$type] ?? [], (array) $names)));
      }
    }
    return new PreparedValues($values, $artifacts, $dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    foreach ($this->children as $index => [$target]) {
      $child = $prepared->artifact[$index] ?? NULL;
      if (!$child instanceof PreparedValues) {
        throw new \InvalidArgumentException('The prepared values did not come from this composite target.');
      }
      $target->commit($child);
    }
  }

  /**
   * Refuses a surface key no child claims, or one two children claim.
   *
   * A key a child lists but the surface does not declare is not an
   * error: a destination may legitimately be told where a key would go
   * before any surface mounts one there, which is how the node type
   * demo names the third party settings mount nothing has used yet.
   * Only the surface's own keys have to be accounted for.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface whose keys the children divide between them.
   *
   * @throws \InvalidArgumentException
   *   When a surface key is claimed by no child, or by more than one.
   */
  protected function checkClaims(DataSurfaceInterface $surface): void {
    $declared = $surface->getDefinitions()->names();
    $claims = array_fill_keys($declared, 0);
    foreach ($this->children as [, $keys]) {
      foreach ($keys ?? $declared as $key) {
        if (array_key_exists($key, $claims)) {
          $claims[$key]++;
        }
      }
    }
    $unclaimed = array_keys(array_filter($claims, static fn (int $count): bool => $count === 0));
    if ($unclaimed !== []) {
      throw new \InvalidArgumentException(sprintf(
        'No child of this composite target stores the %s surface %s, so %s would be accepted and never written.',
        implode(', ', $unclaimed),
        count($unclaimed) === 1 ? 'key' : 'keys',
        count($unclaimed) === 1 ? 'it' : 'they',
      ));
    }
    $shared = array_keys(array_filter($claims, static fn (int $count): bool => $count > 1));
    if ($shared !== []) {
      throw new \InvalidArgumentException(sprintf(
        'More than one child of this composite target stores the %s surface %s, so %s would be written twice.',
        implode(', ', $shared),
        count($shared) === 1 ? 'key' : 'keys',
        count($shared) === 1 ? 'it' : 'they',
      ));
    }
  }

}
