<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * A widget the emitter may choose for a key by what its schema holds.
 *
 * The producer's half of a widget: the emitter reads each key's schema
 * and writes the word of the widget that draws it into the document
 * (`x-portable-ui.widget`), and the form draws the key with the widget
 * that word names. Every stock widget implements this; a module's widget
 * that is chosen by its weight, rather than by a claim or by the word a
 * key asks for, implements it to be chosen at all.
 *
 * Decisions: see docs/decisions.md#the-form-is-drawn-from-the-document
 * and docs/decisions.md#a-widget-is-chosen-by-the-keys-schema.
 *
 * @see \Drupal\surface\Contract\WidgetChoice
 */
interface SchemaWidgetInterface {

  /**
   * Answers whether this widget draws a key.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema, as built (Entry::schema(), or the schema of a
   *   property or an item below it), possibly refined.
   * @param \Drupal\surface\Contract\WidgetChoice $choice
   *   The choice, for a widget that asks which widget draws what it holds.
   *
   * @return bool
   *   TRUE when this widget draws it.
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool;

}
