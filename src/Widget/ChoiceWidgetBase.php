<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use PortableSchema\Builder\Schema;

/**
 * Common ground for the widgets that draw a list of allowed values.
 *
 * A select, radios and checkboxes all read what a schema offers from its
 * `oneOf` (a list's from its `items`), each entry a `const` and the
 * `title` it is offered under, and put each entry's `description` under
 * its option. How long the list may be reused is the served contract's
 * cacheability, which the drawer adds to the whole form.
 */
abstract class ChoiceWidgetBase extends SurfaceWidgetBase {

  /**
   * Reads the schema a list of values offers its choices from.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema itself for one value; its `items` for a list.
   */
  protected static function offering(Schema $schema): ?Schema {
    if (static::type($schema) !== 'array') {
      return $schema;
    }
    $items = $schema->get('items');
    return $items instanceof Schema ? $items : NULL;
  }

  /**
   * Puts each option's description under it, where the document gives one.
   *
   * @param array $element
   *   A radios or checkboxes element.
   * @param array<int|string, string> $descriptions
   *   The descriptions, by value.
   *
   * @return array
   *   The element, an option's child carrying its description.
   */
  protected static function describedOptions(array $element, array $descriptions): array {
    foreach ($descriptions as $value => $description) {
      if (isset($element['#options'][$value])) {
        $element[$value]['#description'] = $description;
      }
    }
    return $element;
  }

}
