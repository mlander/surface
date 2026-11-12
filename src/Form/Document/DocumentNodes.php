<?php

declare(strict_types=1);

namespace Drupal\surface\Form\Document;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use Drupal\surface\Widget\WidgetHostInterface;
use PortableSchema\Builder\Schema;

/**
 * The nodes of one envelope, found by the JSON Pointer of their value.
 *
 * The envelope's schema read inline once (SchemaResolver::inline()), and
 * the values it was emitted for. A pointer is walked from the root as the
 * drawer draws: a property by its name; a list's item by its index, a
 * collection's item its items' object narrowed for that item's values
 * (DocumentDrawer::narrowedFor()); and a key with variants by the variant
 * its frame's conditionals choose for the deciding key's value
 * (DocumentDrawer::resolveVariants()). So the node at an element's
 * pointer is the node the element was drawn from, and extraction reads
 * it here rather than off the element.
 *
 * A key the envelope leaves deferred with no conditional for the value
 * its deciding key holds (a variant inside a collection's item, whose
 * items' object every item shares) is resolved by the host: the document
 * the key's `choose` link would serve for that place and that value
 * (WidgetHostInterface::chosen()), read the same way (chosenVariant()).
 * That document is a full schema, narrowed for no values, so each object
 * below the variant it gives is narrowed by its conditionals for the
 * values it holds (DocumentDrawer::narrowedFor()), as the drawer draws
 * it.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 *
 * @internal
 */
final class DocumentNodes {

  /**
   * The documents the host gave for deferred keys, by pointer and value.
   *
   * @var array<string, \Drupal\surface\Form\Document\DocumentNodes|null>
   */
  protected array $chosen = [];

  /**
   * Constructs a DocumentNodes.
   *
   * @param \PortableSchema\Builder\Schema $root
   *   The envelope's schema, inline.
   * @param array $values
   *   The values it was emitted for.
   * @param \Drupal\surface\Widget\WidgetHostInterface|null $host
   *   What resolves a deferred key, or NULL where nothing does.
   */
  public function __construct(
    public readonly Schema $root,
    public readonly array $values,
    protected readonly ?WidgetHostInterface $host = NULL,
  ) {}

  /**
   * Reads an envelope's nodes.
   *
   * @param \Drupal\surface\Contract\ServedContract $contract
   *   The envelope.
   * @param \Drupal\surface\Widget\WidgetHostInterface|null $host
   *   What resolves a deferred key, or NULL where nothing does.
   *
   * @return static
   *   Its nodes.
   */
  public static function of(ServedContract $contract, ?WidgetHostInterface $host = NULL): static {
    $values = $contract->document['values'] ?? NULL;
    return new static(SchemaResolver::inline($contract->schema()), is_array($values) ? $values : [], $host);
  }

  /**
   * Finds the node of the value at a JSON Pointer.
   *
   * @param string $pointer
   *   The pointer, '' for the root.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The node, or NULL where the document draws nothing there: no such
   *   key, or variants nothing chose.
   */
  public function at(string $pointer): ?Schema {
    $node = $this->root;
    $values = $this->values;
    $at = '';
    $narrows = FALSE;
    foreach (WidgetContext::parentsOf($pointer) as $step) {
      if ($node->isBoolean()) {
        return NULL;
      }
      if ($narrows && SurfaceWidgetBase::type($node) !== 'array') {
        $node = DocumentDrawer::narrowedFor($node, is_array($values) ? $values : [], TRUE);
      }
      $here = $at . '/' . WidgetContext::escape($step);
      $held = is_array($values) ? ($values[$step] ?? NULL) : NULL;
      if (SurfaceWidgetBase::type($node) === 'array') {
        $items = $node->get('items');
        if (!$items instanceof Schema) {
          return NULL;
        }
        $node = $items->isBoolean() ? $items : DocumentDrawer::narrowedFor($items, is_array($held) ? $held : [], $narrows);
      }
      else {
        $child = ((array) ($node->get('properties') ?? []))[$step] ?? NULL;
        if (!$child instanceof Schema || $child->isBoolean()) {
          return NULL;
        }
        if (static::isVariants($child)) {
          $frame_values = is_array($values) ? $values : [];
          $found = DocumentDrawer::resolveVariants($node, $step, $frame_values);
          if ($found === NULL) {
            $found = $this->chosenVariant($node, $step, $here, $frame_values);
            $narrows = $narrows || $found !== NULL;
          }
          $child = $found;
        }
        if ($child === NULL) {
          return NULL;
        }
        $node = $child;
      }
      $values = $held;
      $at = $here;
    }
    return $node;
  }

  /**
   * Finds a deferred variant in the host's document for its value.
   *
   * Where the frame's conditionals choose no variant and the key is
   * deferred (`x-surface.deferred`) while its deciding key holds a value,
   * the host's document for that place and that value states it: its
   * frame at the same place chooses it. That document is narrowed for no
   * values: what it gives is drawn narrowing each frame below it.
   *
   * @param \PortableSchema\Builder\Schema $frame
   *   The object holding the key, inline.
   * @param string $name
   *   The key.
   * @param string $pointer
   *   The key's JSON Pointer.
   * @param array $frame_values
   *   What the object holds.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The variant's node, or NULL when nothing is chosen.
   */
  public function chosenVariant(Schema $frame, string $name, string $pointer, array $frame_values): ?Schema {
    if ($this->host === NULL) {
      return NULL;
    }
    $key = ((array) ($frame->get('properties') ?? []))[$name] ?? NULL;
    $extension = $key instanceof Schema && !$key->isBoolean() ? SurfaceWidgetBase::extension($key) : [];
    $by = $extension['by'] ?? NULL;
    $value = is_string($by) ? ($frame_values[$by] ?? NULL) : NULL;
    if (empty($extension['deferred']) || !is_scalar($value) || (string) $value === '') {
      return NULL;
    }
    $chosen = $this->chosen[$pointer . "\n" . $value] ??= $this->chosenFor($pointer, $value);
    $parents = WidgetContext::parentsOf($pointer);
    array_pop($parents);
    $there = $chosen?->at(WidgetContext::pointerOf($parents));
    return $there === NULL ? NULL : DocumentDrawer::resolveVariants($there, $name, $frame_values);
  }

  /**
   * Reads the host's document for a deferred key's chosen value.
   *
   * @param string $pointer
   *   The key's JSON Pointer.
   * @param string|int|float|bool $value
   *   Its deciding key's value.
   *
   * @return \Drupal\surface\Form\Document\DocumentNodes|null
   *   That document's nodes, walked for the same values with no host, or
   *   NULL where the host has none.
   */
  protected function chosenFor(string $pointer, string|int|float|bool $value): ?DocumentNodes {
    $contract = $this->host?->chosen($pointer, $value);
    return $contract === NULL ? NULL : new static(SchemaResolver::inline($contract->schema()), $this->values);
  }

  /**
   * Answers whether a key's node is a key with variants.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's node.
   *
   * @return bool
   *   TRUE for the `variants` word or an `x-surface.by`.
   */
  public static function isVariants(Schema $node): bool {
    return (SurfaceWidgetBase::ui($node)['widget'] ?? NULL) === 'variants' || isset(SurfaceWidgetBase::extension($node)['by']);
  }

}
