<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use PortableSchema\Builder\Schema;

/**
 * Draws the schemas inside a schema, as the form draws any of the document.
 *
 * A widget that holds other keys hands them back to the drawer, so every
 * key is drawn by the same rules wherever it sits: an object's
 * properties (groups, variants, collections, a key that does not apply,
 * a lock), and a list's rows, each its item schema drawn whole.
 *
 * @see \Drupal\surface\Form\Document\DocumentDrawer
 */
interface SchemaDrawerInterface {

  /**
   * Draws the properties of an object schema.
   *
   * @param \PortableSchema\Builder\Schema $object
   *   The object schema, inline.
   * @param mixed $value
   *   What it holds.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   The object's context.
   *
   * @return array
   *   One element per property, keyed by name, and an element per group
   *   the object declares.
   */
  public function drawProperties(Schema $object, mixed $value, WidgetContext $context): array;

  /**
   * Draws one schema whole: its widget, its hints and its markers.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema, inline.
   * @param mixed $value
   *   What it holds.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   The schema's context.
   *
   * @return array
   *   The element.
   */
  public function drawSchema(Schema $schema, mixed $value, WidgetContext $context): array;

}
