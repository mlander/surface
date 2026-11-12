<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use PortableSchema\Builder\Schema;

/**
 * Where a node is drawn, and what the host answers about it.
 *
 * What a node does not say of itself: its JSON Pointer in the values,
 * whether the object that holds it requires it (the owner's `required`),
 * whether the value shown stands for a stale one (the envelope's
 * `stale`), and whether an element around it is locked, which it then
 * inherits, and whether it is drawn inside a collection's item, whose
 * items' schema every item shares, so what that schema answers for one
 * value (an empty option) is read again for the item's own; and whether
 * it is drawn from a document not narrowed for these values (a deferred
 * key's chosen document, a full schema), whose frames are narrowed by
 * their conditionals as they are drawn. Beside
 * those, the host's answers (WidgetHostInterface) and the drawer, which
 * draws the nodes inside a node.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
final class WidgetContext {

  /**
   * Constructs a WidgetContext.
   *
   * @param string $pointer
   *   The JSON Pointer of the node's value, '' for the whole.
   * @param bool $required
   *   Whether the object holding the node lists it in `required`.
   * @param bool $stale
   *   Whether the envelope lists the node's pointer in `stale`.
   * @param bool $locked
   *   Whether an element around the node is drawn locked.
   * @param \Drupal\surface\Widget\WidgetHostInterface|null $host
   *   The host's answers, or NULL where there is no host.
   * @param \Drupal\surface\Widget\NodeDrawerInterface|null $drawer
   *   The drawer, or NULL where nothing draws the nodes inside.
   * @param string[] $stalePointers
   *   Every stale pointer of the envelope, for the nodes inside.
   * @param bool $item
   *   Whether the node is drawn inside a collection's item.
   * @param bool $narrows
   *   Whether the node comes from a document not narrowed for the values,
   *   whose frames are narrowed as they are drawn.
   */
  public function __construct(
    public readonly string $pointer = '',
    public readonly bool $required = FALSE,
    public readonly bool $stale = FALSE,
    public readonly bool $locked = FALSE,
    protected readonly ?WidgetHostInterface $host = NULL,
    protected readonly ?NodeDrawerInterface $drawer = NULL,
    protected readonly array $stalePointers = [],
    public readonly bool $item = FALSE,
    public readonly bool $narrows = FALSE,
  ) {}

  /**
   * Gets the context of a node inside this one.
   *
   * @param string|int $name
   *   The property name, or a list item's index.
   * @param bool $required
   *   Whether this node requires it.
   * @param bool $locked
   *   Whether it is drawn inside a locked element.
   *
   * @return static
   *   The context.
   */
  public function at(string|int $name, bool $required = FALSE, bool $locked = FALSE): static {
    $pointer = $this->pointer . '/' . static::escape((string) $name);
    return new static($pointer, $required, in_array($pointer, $this->stalePointers, TRUE), $locked || $this->locked, $this->host, $this->drawer, $this->stalePointers, $this->item, $this->narrows);
  }

  /**
   * Gets this context, inside a collection's item.
   *
   * @return static
   *   The same context, and every one inside it, in an item.
   */
  public function inItem(): static {
    return new static($this->pointer, $this->required, $this->stale, $this->locked, $this->host, $this->drawer, $this->stalePointers, TRUE, $this->narrows);
  }

  /**
   * Gets this context, drawn from a document not narrowed for the values.
   *
   * @return static
   *   The same context, and every one inside it, narrowing its frames.
   */
  public function narrowing(): static {
    return new static($this->pointer, $this->required, $this->stale, $this->locked, $this->host, $this->drawer, $this->stalePointers, $this->item, TRUE);
  }

  /**
   * Gets this context, inside a locked element.
   *
   * @return static
   *   The same context, locked.
   */
  public function locked(): static {
    return new static($this->pointer, $this->required, $this->stale, TRUE, $this->host, $this->drawer, $this->stalePointers, $this->item, $this->narrows);
  }

  /**
   * Gets the name of the node: the last step of its pointer.
   *
   * @return string
   *   The name, '' for the whole.
   */
  public function name(): string {
    $parents = static::parentsOf($this->pointer);
    return $parents === [] ? '' : (string) end($parents);
  }

  /**
   * Gives the label of the entity a reference names.
   *
   * @see \Drupal\surface\Widget\WidgetHostInterface::labelOf()
   */
  public function labelOf(string $entity_type_id, int|string $id): ?string {
    return $this->host?->labelOf($entity_type_id, $id);
  }

  /**
   * Gives the selection handler and settings of a reference key.
   *
   * @see \Drupal\surface\Widget\WidgetHostInterface::selection()
   */
  public function selection(string $pointer): ?array {
    return $this->host?->selection($pointer);
  }

  /**
   * Draws the properties of an object node, as the drawer draws a frame.
   *
   * @see \Drupal\surface\Widget\NodeDrawerInterface::drawProperties()
   */
  public function properties(Schema $object, mixed $value): array {
    return $this->drawer?->drawProperties($object, $value, $this) ?? [];
  }

  /**
   * Draws one node whole, as the drawer draws a key.
   *
   * @see \Drupal\surface\Widget\NodeDrawerInterface::drawNode()
   */
  public function node(Schema $node, mixed $value): array {
    return $this->drawer?->drawNode($node, $value, $this) ?? [];
  }

  /**
   * Escapes a step of a JSON Pointer.
   *
   * @param string $step
   *   The step.
   *
   * @return string
   *   `~` as `~0`, `/` as `~1`.
   */
  public static function escape(string $step): string {
    return str_replace(['~', '/'], ['~0', '~1'], $step);
  }

  /**
   * Reads a JSON Pointer as the steps of a value path.
   *
   * @param string $pointer
   *   The pointer.
   *
   * @return string[]
   *   The steps, unescaped; none for ''.
   */
  public static function parentsOf(string $pointer): array {
    if ($pointer === '') {
      return [];
    }
    return array_map(static fn (string $step): string => str_replace(['~1', '~0'], ['/', '~'], $step), explode('/', substr($pointer, 1)));
  }

  /**
   * Writes a value path as a JSON Pointer.
   *
   * @param array $parents
   *   The steps.
   *
   * @return string
   *   The pointer.
   */
  public static function pointerOf(array $parents): string {
    return implode('', array_map(static fn (string|int $step): string => '/' . static::escape((string) $step), $parents));
  }

}
