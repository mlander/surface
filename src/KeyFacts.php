<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Contract\SchemaFacts;
use PortableSchema\Builder\Schema;

/**
 * Reads what a widget or a refiner asks of a key's schema.
 *
 * The public reader for code outside the module: a widget's
 * drawsSchema() and isApplicable(), a refiner's refine(). It answers four
 * questions of a built schema, the one a widget or a refiner is handed: the
 * key's type, one of its settings, whether it holds a list, and the schema
 * of that list's items. Every other fact of the key is a keyword of the
 * schema, read with Schema::get() (`title`, `maxLength`, `format`).
 *
 * Static and pure: no service, no request, nothing kept.
 *
 * Decision: see docs/decisions.md#widgets-read-a-key-through-keyfacts.
 *
 * @api
 */
final class KeyFacts {

  /**
   * Names the type a key holds, as the dialect names it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return string
   *   For one value, the id of the Portable Schema library type the key
   *   was read as (`portable:string`, `portable:integer`,
   *   `portable:number`, `portable:boolean`, `portable:email`,
   *   `portable:uri`, `portable:date-time`, `portable:timestamp`); for a
   *   list, `array`; for a map, `object`; for any other value, `any`.
   */
  public static function type(Schema $schema): string {
    if (DefinitionSchema::isList($schema)) {
      return 'array';
    }
    if (DefinitionSchema::isComplex($schema)) {
      return 'object';
    }
    return array_flip(LeafTypes::LIBRARY)[SchemaFacts::dataType($schema)] ?? LeafTypes::ANY;
  }

  /**
   * Gets one setting of a key.
   *
   * What the key's `x-surface-settings` says, and what its widget
   * publishes and the key's `x-portable-ui` sets under `options`, each by
   * the setting's name; a key declared with the verbs holds its settings
   * by the same names.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param string $name
   *   The setting's name, such as `widget` or a widget's own setting.
   *
   * @return mixed
   *   The setting; NULL where the key sets none of that name.
   */
  public static function setting(Schema $schema, string $name): mixed {
    return SchemaFacts::setting($schema, $name);
  }

  /**
   * Answers whether a key holds a list.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return bool
   *   TRUE for a list of values.
   */
  public static function isList(Schema $schema): bool {
    return DefinitionSchema::isList($schema);
  }

  /**
   * Gets the schema of each item of a list.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema of the list's items; NULL for a key that holds no list.
   */
  public static function item(Schema $schema): ?Schema {
    return DefinitionSchema::isList($schema) ? DefinitionSchema::itemOf($schema) : NULL;
  }

}
