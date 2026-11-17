<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use PortableSchema\Builder\Schema;

/**
 * Derives a definition from a built schema: the inverse of the read.
 *
 * An edge: Typed Data is made here from the model, and nowhere else. The
 * rules:
 * - It reads the schema alone, never the definition the schema was read from:
 *   each key of the definition array from its place (DefinitionSchema::
 *   PLACES), its member where the schema has one, else its keyword, then
 *   the keys under `stored`.
 * - It derives three classes, by exact class: DataDefinition,
 *   ListDataDefinition and MapDataDefinition, by their constructors. A
 *   schema holds every fact of a definition of these classes. A schema read
 *   from any other class (a reference, which keeps its target outside the
 *   definition array; a field item, which keeps its field definition; an
 *   entity, whose properties are core's own objects; a FieldConfig; a
 *   subclass of the three) is refused with a LogicException naming the
 *   class: the schema does not hold what that class keeps outside its
 *   array, so no definition derived from it would be equal.
 * - A schema of a data type core's typed data manager does not define is
 *   refused with a LogicException naming the type: the definition was read
 *   from another manager's definition (typed config's), which the schema
 *   does not name. So is a schema whose derived definition does not give
 *   the constraints the schema was read with (SchemaFacts::constraints()):
 *   the type is core's, but the definition took its type's defaults from
 *   another manager (a typed config `text` where a module defines a typed
 *   data `text`). Where the schema holds the `constraints` member (a type
 *   with no row in LeafTypes::DEFAULT_CONSTRAINTS, such as `text`), that
 *   is the list the definition read gave. Where it holds none, the list
 *   is derived from the table, so the check compares the table's row with
 *   what the site's typed data manager gives the type: the check
 *   DefaultConstraintsTest makes on the test site, made here on the site
 *   the schema is derived on.
 * - It makes a new definition wherever the schema holds a schema: a list's
 *   item, a map's properties, a shape's, a config type's and `each`'s
 *   definition. Every other value is the object the schema holds, which is
 *   the object the definition read holds: a text, a Computed, a Resolve,
 *   an Arrangement, an object or a definition in a setting or under a key
 *   the module has no name for. Nothing is cloned, so a write through
 *   such an object is a write to the original's.
 * - What it calls in core: the three classes' constructors,
 *   setPropertyDefinition(), setMainPropertyName(), getConstraints() on
 *   the definition it made (which asks the typed data manager for the
 *   type's defaults, inside core), and its one service call,
 *   `\Drupal::typedDataManager()->hasDefinition()`. It uses no Surface
 *   service.
 * The proof is a full round trip (DefinitionRoundTripTest): a definition
 * of the three classes read into a schema and derived back equals the
 * definition.
 *
 * Decision: see
 * docs/decisions.md#a-schema-holds-every-fact-of-its-definition-and-a-definition-is-derived-back-from-it.
 *
 * @internal
 */
final class DefinitionFromSchema {

  /**
   * The classes a definition is derived as, by exact class.
   */
  public const DERIVED = [
    DataDefinition::class,
    ListDataDefinition::class,
    MapDataDefinition::class,
  ];

  /**
   * Derives the definition a built schema was read from.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema (DefinitionSchema::of()).
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A new definition, equal to the one the schema was read from.
   *
   * @throws \LogicException
   *   For a schema no definition was read into, one of a class other than
   *   the three, or one of a data type core's typed data manager does not
   *   define as the schema was read.
   */
  public static function of(Schema $schema): DataDefinitionInterface {
    $model = DefinitionSchema::model($schema);
    if (!isset($model['type']) || !is_string($model['type'])) {
      throw new \LogicException('A definition is derived from a built schema alone: this schema holds no data type in ' . DefinitionSchema::MODEL . '.');
    }
    $type = $model['type'];
    $class = DefinitionSchema::classOf($schema);
    if (!in_array($class, self::DERIVED, TRUE)) {
      throw new \LogicException(sprintf('No definition of the %s class is derived from a schema: only a DataDefinition, a ListDataDefinition and a MapDataDefinition are, since the schema does not hold what another class keeps outside its definition array.', $class));
    }
    if (!\Drupal::typedDataManager()->hasDefinition($type)) {
      throw new \LogicException(sprintf('No definition of the %s type is derived from a schema: core\'s typed data manager does not define the type, so the definition was read from another manager\'s definition.', $type));
    }
    $values = static::values($schema, $model);
    $items = $schema->get('items');
    $derived = match ($class) {
      ListDataDefinition::class => new ListDataDefinition($values, $items instanceof Schema ? static::of($items) : NULL),
      MapDataDefinition::class => static::properties(new MapDataDefinition($values), $schema, $model),
      default => new DataDefinition($values),
    };
    if ($derived->getConstraints() !== SchemaFacts::constraints($schema)) {
      throw new \LogicException(sprintf('No definition of the %s type is derived from a schema: core\'s typed data manager gives the type other constraints than the schema was read with, so the definition was read from another manager\'s definition.', $type));
    }
    return $derived;
  }

  /**
   * Writes the definition array from a schema's places.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param array<string, mixed> $model
   *   Its MODEL.
   *
   * @return array
   *   The array: the keys PLACES names, in its order, then the keys under
   *   `stored`.
   */
  private static function values(Schema $schema, array $model): array {
    $values = [];
    foreach (DefinitionSchema::PLACES as $key => [$member, $keyword]) {
      if ($member === 'type') {
        $values[$key] = $model['type'];
      }
      elseif (array_key_exists($member, $model)) {
        $values[$key] = static::member($member, $model[$member]);
      }
      elseif ($keyword !== NULL && $schema->has($keyword)) {
        $values[$key] = $schema->get($keyword);
      }
    }
    foreach ((array) ($model['stored'] ?? []) as $key => $value) {
      $values[$key] = $value;
    }
    return $values;
  }

  /**
   * Derives what a member holds back into what the array stores.
   *
   * @param string $member
   *   The member.
   * @param mixed $value
   *   What it holds.
   *
   * @return mixed
   *   The value as the definition array stores it: a definition derived
   *   from each schema a shape, a config type and `each` hold.
   */
  private static function member(string $member, mixed $value): mixed {
    return match ($member) {
      'shape' => ['id' => $value['id'], 'definition' => static::of($value['schema'])],
      'configType' => ['type' => $value['type'], 'definition' => static::of($value['schema'])],
      'each' => $value instanceof Schema ? static::of($value) : $value,
      default => $value,
    };
  }

  /**
   * Gives a map its properties and its main property, from its schema.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   * @param array<string, mixed> $model
   *   Its MODEL.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  private static function properties(MapDataDefinition $map, Schema $schema, array $model): MapDataDefinition {
    $properties = $schema->get('properties');
    foreach (is_array($properties) ? $properties : [] as $name => $property) {
      $map->setPropertyDefinition((string) $name, static::of($property));
    }
    if (array_key_exists('mainProperty', $model)) {
      $map->setMainPropertyName($model['mainProperty']);
    }
    return $map;
  }

}
