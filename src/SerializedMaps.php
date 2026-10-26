<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Keeps what core leaves out when a map definition is serialized.
 *
 * Core's ComplexDataDefinitionBase::__sleep() drops a map's property
 * definitions, which a derived definition computes again; a map a data
 * surface holds (an attached child's map, a mounted module's settings, a
 * variants' shell) was given them with setPropertyDefinition(), and would
 * wake with none. A sealed data surface stores, beside itself, each map
 * it holds with its properties (export()), and gives them back when it
 * wakes (restore()), so a build kept in the build cache reads the same
 * definitions as the one that was kept.
 *
 * It walks what the data surface itself holds: its maps of entries, each
 * entry's definition, children's shells and wiring, ports, and what
 * refinement holds. A child data surface keeps its own.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @internal
 */
final class SerializedMaps {

  /**
   * The classes walked into: what a sealed data surface is made of.
   */
  private const WALKED = [
    DefinitionMap::class,
    Entry::class,
    Variants::class,
    Collection::class,
    Mixin::class,
    Port::class,
    Computed::class,
  ];

  /**
   * Lists every map reachable from some values, with its properties.
   *
   * @param array $values
   *   What the data surface holds, by property.
   *
   * @return array<int, array{0: \Drupal\Core\TypedData\MapDataDefinition, 1: array<string, \Drupal\Core\TypedData\DataDefinitionInterface>}>
   *   Each map once, with the properties it was given.
   */
  public static function export(array $values): array {
    $maps = [];
    $seen = [];
    static::walk($values, $maps, $seen);
    return array_values($maps);
  }

  /**
   * Gives each map back the properties export() listed.
   *
   * @param array<int, array{0: \Drupal\Core\TypedData\MapDataDefinition, 1: array<string, \Drupal\Core\TypedData\DataDefinitionInterface>}> $maps
   *   What export() answered, unserialized.
   */
  public static function restore(array $maps): void {
    foreach ($maps as [$map, $properties]) {
      foreach ($properties as $name => $property) {
        $map->setPropertyDefinition((string) $name, $property);
      }
    }
  }

  /**
   * Walks one value for export().
   *
   * @param mixed $value
   *   The value.
   * @param array $maps
   *   The maps found, by object id.
   * @param array<int, true> $seen
   *   The objects walked, by object id.
   */
  private static function walk(mixed $value, array &$maps, array &$seen): void {
    if (is_array($value)) {
      foreach ($value as $item) {
        if (is_array($item) || is_object($item)) {
          static::walk($item, $maps, $seen);
        }
      }
      return;
    }
    if (!is_object($value) || isset($seen[spl_object_id($value)])) {
      return;
    }
    $seen[spl_object_id($value)] = TRUE;
    if ($value instanceof DataDefinitionInterface) {
      if ($value instanceof MapDataDefinition) {
        $properties = $value->getPropertyDefinitions();
        if ($properties !== []) {
          $maps[spl_object_id($value)] = [$value, $properties];
        }
        static::walk($properties, $maps, $seen);
      }
      if ($value instanceof ListDataDefinitionInterface) {
        static::walk($value->getItemDefinition(), $maps, $seen);
      }
      return;
    }
    if ($value instanceof Attachment) {
      // What its ports read, as declared; its child keeps its own.
      static::walk($value->wired, $maps, $seen);
      return;
    }
    if (in_array($value::class, self::WALKED, TRUE) || str_starts_with($value::class, 'Drupal\\data_surface\\Refinement\\')) {
      // Every property, whatever its visibility.
      static::walk((array) $value, $maps, $seen);
    }
  }

}
