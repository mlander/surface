<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\DefinitionMetadata;

/**
 * The typed data types a key may hold one value of, and their JSON Schema.
 *
 * One row per type: the `type` and `format` JSON Schema states it with.
 * The blueprint refuses a key whose value is of a type not here, the
 * emitter writes each row, and the reader reads a JSON type and format
 * back into the row's type, so the contract states a type for every
 * value the runtime accepts and is never vaguer than it.
 *
 * How a value of each type is drawn and coerced is keyed by the same type
 * where it is done, and not repeated here: each widget's isApplicable()
 * (StringWidget, NumberWidget, BooleanWidget, DateTimeWidget) and the
 * coercion table (Coercion::scalar()).
 *
 * One value is of no type here and still held: `any`, in two places the
 * module makes it and an author never declares it. A variants'
 * placeholder (DefinitionMetadata::variantsBy()) stands for the variant
 * its deciding key chooses, which the emitter writes as conditionals; a
 * value a schema read states and no type here says (several JSON types,
 * say) is held whole to that schema by a JsonSchema constraint, which the
 * emitter writes as the schema it keeps (DefinitionMetadata::isHeldWhole()).
 *
 * Its rows are JSON Schema spellings, the contract's words, so it lives
 * here; the blueprint reads it as it reads SchemaReader.
 *
 * Decision: see
 * docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader.
 *
 * @see \Drupal\surface\Pipeline\Coercion::scalar()
 *
 * @internal
 */
final class LeafTypes {

  /**
   * The JSON Schema each type is written with, by type.
   *
   * Read in order to read a JSON type back: `integer` before `timestamp`.
   * A string key drawn as a date is `format: date` besides, which the
   * emitter adds (DateWidget::draws()). Drupal's email validator admits a
   * non-ASCII local part, which `email` (RFC 5321) refuses and
   * `idn-email` (RFC 6531) admits.
   */
  public const ROWS = [
    'string' => ['type' => 'string'],
    'email' => ['type' => 'string', 'format' => 'idn-email'],
    'uri' => ['type' => 'string', 'format' => 'uri'],
    'datetime_iso8601' => ['type' => 'string', 'format' => 'date-time'],
    'integer' => ['type' => 'integer'],
    'timestamp' => ['type' => 'integer'],
    'float' => ['type' => 'number'],
    'boolean' => ['type' => 'boolean'],
  ];

  /**
   * Formats read as a type whose row writes another, by format.
   *
   * `email` is read as Drupal's email, written back as `idn-email`.
   */
  public const READ_FORMATS = [
    'email' => 'email',
  ];

  /**
   * The type that is held only where the module makes it.
   */
  public const ANY = 'any';

  /**
   * Answers whether a type is a row.
   *
   * @param string $type
   *   The typed data type.
   *
   * @return bool
   *   TRUE for a type a key may be declared with.
   */
  public static function has(string $type): bool {
    return isset(self::ROWS[$type]);
  }

  /**
   * Gets the JSON Schema a leaf is written with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   One value: no map and no list.
   *
   * @return array
   *   `type`, and `format` where the row has one; empty for `any`, whose
   *   conditionals or kept schema say it.
   *
   * @throws \LogicException
   *   For a value of no row, which the blueprint refuses first.
   */
  public static function spelling(DataDefinitionInterface $definition): array {
    $type = $definition->getDataType();
    if (isset(self::ROWS[$type])) {
      return self::ROWS[$type];
    }
    if (static::heldAsAny($definition)) {
      return [];
    }
    throw new \LogicException(sprintf('"%s" is of the %s type, which no JSON Schema type is written for: the blueprint holds every key to the types %s.', $definition->getLabel() ?? $type, $type, implode(', ', array_keys(self::ROWS))));
  }

  /**
   * Reads the type a JSON type and format are written for.
   *
   * @param string $json
   *   The JSON type: `string`, `integer`, `number` or `boolean`.
   * @param string|null $format
   *   The format, if any.
   *
   * @return string|null
   *   The typed data type: the first row that writes both, else the first
   *   that writes the type with no format; NULL for a JSON type no row
   *   writes.
   */
  public static function read(string $json, ?string $format = NULL): ?string {
    if ($json === 'string' && $format !== NULL && isset(self::READ_FORMATS[$format])) {
      return self::READ_FORMATS[$format];
    }
    $plain = NULL;
    foreach (self::ROWS as $type => $row) {
      if ($row['type'] !== $json) {
        continue;
      }
      if ($format !== NULL && ($row['format'] ?? NULL) === $format) {
        return $type;
      }
      if (!isset($row['format'])) {
        $plain ??= $type;
      }
    }
    return $plain;
  }

  /**
   * Answers whether a format is one a row writes.
   *
   * @param string $format
   *   The format.
   *
   * @return bool
   *   TRUE when reading it gives a type of its own.
   */
  public static function readsFormat(string $format): bool {
    if (isset(self::READ_FORMATS[$format])) {
      return TRUE;
    }
    foreach (self::ROWS as $row) {
      if (($row['format'] ?? NULL) === $format) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a definition holds one value rather than keys or items.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   FALSE for a map or a list.
   */
  public static function isLeaf(DataDefinitionInterface $definition): bool {
    return !$definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface;
  }

  /**
   * Answers whether an `any` value is one the module made and holds.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a variants' placeholder, or a value held whole to the
   *   schema it was read from.
   */
  public static function heldAsAny(DataDefinitionInterface $definition): bool {
    return $definition->getDataType() === self::ANY
      && (DefinitionMetadata::variantsBy($definition) !== NULL || DefinitionMetadata::isHeldWhole($definition));
  }

  /**
   * Refuses a definition that holds a value of no row, at any depth.
   *
   * Walks a map's properties and a list's item; every value inside is
   * held as add() holds a key.
   *
   * @param string $verb
   *   The call, for the message: `add("price")`.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $path
   *   Where the walk is, for the message; empty at the top.
   *
   * @throws \LogicException
   *   For a value of a type not in the table.
   */
  public static function assertHeld(string $verb, DataDefinitionInterface $definition, string $path = ''): void {
    if ($definition instanceof ListDataDefinitionInterface) {
      static::assertHeld($verb, $definition->getItemDefinition(), $path === '' ? '*' : $path . '.*');
      return;
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        static::assertHeld($verb, $property, $path === '' ? (string) $name : $path . '.' . $name);
      }
      return;
    }
    if (static::has($definition->getDataType()) || static::heldAsAny($definition)) {
      return;
    }
    throw new \LogicException(sprintf(
      '%s declares %s of the %s type, which JSON Schema has no type for. A key holds a value of one of %s; %s',
      $verb,
      $path === '' ? 'a value' : sprintf('"%s"', $path),
      $definition->getDataType(),
      implode(', ', array_keys(self::ROWS)),
      static::meant($definition->getDataType()),
    ));
  }

  /**
   * Answers whether a type string spells an entity, which is no type here.
   *
   * Typed data's `entity`, `entity:<type>` and `entity:<type>:<bundle>`:
   * an id and a constraint, the id's type with SurfaceValidReference, for
   * a key and for each entry of addEach() alike.
   *
   * @param string $type
   *   The type string.
   *
   * @return bool
   *   TRUE for an entity spelling.
   */
  public static function refersToEntity(string $type): bool {
    return $type === 'entity' || str_starts_with($type, 'entity:');
  }

  /**
   * Names the method for what a type that is no row was meant as.
   *
   * @param string $type
   *   The type.
   *
   * @return string
   *   The sentence.
   */
  public static function meant(string $type): string {
    return match (TRUE) {
      static::refersToEntity($type) => 'an id of an entity is add(\'<key>\', \'integer\' or \'string\', ...), the type the entity type keeps its id as, with the SurfaceValidReference constraint naming the entity type: ->addConstraint(\'SurfaceValidReference\', [\'entityTypeId\' => \'<entity type>\']).',
      $type === 'map' => 'a group of keys is attach(\'<key>\', <structure>::class), or addMap() for keys only this one holds.',
      $type === 'list' => 'a list is addList().',
      $type === self::ANY => 'a value whose shape depends on another key is variants, attachVariants().',
      default => 'a typed data definition built by hand is addDefinition(), held to the same types.',
    };
  }

}
