<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use PortableSchema\Builder\Schema;

/**
 * Draws the nodes inside a node, as the form draws any of the document.
 *
 * A widget that holds other keys hands them back to the drawer, so every
 * key is drawn by the same rules wherever it sits: an object's
 * properties (groups, variants, collections, a key that does not apply,
 * a lock), and a list's rows, each its item node drawn whole.
 *
 * @see \Drupal\surface\Form\Document\DocumentDrawer
 */
interface NodeDrawerInterface {

  /**
   * Draws the properties of an object node.
   *
   * @param \PortableSchema\Builder\Schema $object
   *   The object node, inline.
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
   * Draws one node whole: its widget, its hints and its markers.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node, inline.
   * @param mixed $value
   *   What it holds.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   The node's context.
   *
   * @return array
   *   The element.
   */
  public function drawNode(Schema $node, mixed $value, WidgetContext $context): array;

}
