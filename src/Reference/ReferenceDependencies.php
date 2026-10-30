<?php

declare(strict_types=1);

namespace Drupal\surface\Reference;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Keeps a config entity's reference dependencies through its save.
 *
 * Core recalculates a config entity's dependencies on every save, from
 * its plugins and its third party settings; a key that refers to another
 * entity is neither, and the entity class, which core expects to add the
 * rest (calculateDependencies()), is not this module's. So
 * ConfigEntityStorage::commit() saves through during(), handing it what
 * the rehearsal found the keys refer to, and the presave hook, which core
 * invokes after the entity recalculated its own, adds them
 * (ReferenceDependencyHooks). A save made any other way recalculates
 * without them.
 *
 * Decision: see docs/decisions.md#a-config-object-stores-a-reference-by-uuid.
 *
 * @internal
 */
final class ReferenceDependencies {

  /**
   * The dependency types a reference brings.
   */
  public const TYPES = ['config', 'content'];

  /**
   * Core's order of a config entity's dependency types.
   */
  protected const ORDER = ['config', 'content', 'module', 'theme', 'enforced'];

  /**
   * The dependencies held for each entity being saved, by object id.
   *
   * @var array<int, array<string, string[]>>
   */
  protected static array $held = [];

  /**
   * Saves an entity with the dependencies its references bring.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param array<string, string[]> $dependencies
   *   The dependency names, by type (TYPES).
   * @param callable $save
   *   What saves it.
   */
  public static function during(ConfigEntityInterface $entity, array $dependencies, callable $save): void {
    $key = spl_object_id($entity);
    static::$held[$key] = $dependencies;
    try {
      $save();
    }
    finally {
      unset(static::$held[$key]);
    }
  }

  /**
   * Adds the dependencies held for an entity to what it calculated.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity being saved, its own dependencies calculated.
   */
  public static function add(ConfigEntityInterface $entity): void {
    $held = static::$held[spl_object_id($entity)] ?? [];
    if ($held === []) {
      return;
    }
    $dependencies = (array) ($entity->get('dependencies') ?? []);
    foreach ($held as $type => $names) {
      $merged = array_merge((array) ($dependencies[$type] ?? []), array_map('strval', (array) $names));
      $merged = array_values(array_unique($merged));
      sort($merged);
      $dependencies[$type] = $merged;
    }
    $ordered = [];
    foreach (self::ORDER as $type) {
      if (isset($dependencies[$type])) {
        $ordered[$type] = $dependencies[$type];
      }
    }
    $entity->set('dependencies', $ordered + $dependencies);
  }

}
