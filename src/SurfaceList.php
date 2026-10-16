<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Surface\SurfaceContext;

/**
 * A key whose value is a list of child surface instances: a collection.
 *
 * What attachList() leaves on the entry it declares. Every item is the
 * same child, sealed once: an item's frame is the child's own, under the
 * child's key names, so nothing in the parent can name a key inside an
 * item and nothing in an item can see the parent, the wall attach()
 * draws, once per item. The child is built once per list because a
 * shape cannot depend on a value, and how many items there are is a
 * value; what differs from item to item is the values each is refined,
 * accepted and validated against, and its position.
 *
 * The list key itself is the parent's: its definition is a core
 * ListDataDefinition whose item is the child's map, carrying the Count
 * the parent declared and its refiners narrow, and the parent's lock.
 * The child's situation, and its access, were answered once for the
 * list: field access is per field, not per delta.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::attachList()
 * @see \Drupal\data_surface\SurfaceAttachment
 */
final class SurfaceList {

  /**
   * Constructs a SurfaceList.
   *
   * @param \Drupal\data_surface\SurfaceAttachment $item
   *   The child every item is, sealed once, as it was advertised.
   * @param \Drupal\data_surface\Surface\SurfaceContext|null $context
   *   The context every item was invoked in, the child's situation
   *   resolved for the list; contextFor() adds an item's delta to what
   *   it knows. NULL for a list handed over already sealed.
   */
  public function __construct(
    public readonly SurfaceAttachment $item,
    public readonly ?SurfaceContext $context = NULL,
  ) {
  }

  /**
   * Gets the child every item is.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The child, as advertised.
   */
  public function child(): DataSurfaceInterface {
    return $this->item->child;
  }

  /**
   * Gets the context one item is in: the list's, knowing its delta.
   *
   * Delta is position. It is added to the item's known identity, so
   * whatever reads an item's context (a target, a situation that takes a
   * delta) knows which item it is; nothing stores it.
   *
   * @param int $delta
   *   The item's position, from zero.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext|null
   *   The context, or NULL when the list carries none.
   */
  public function contextFor(int $delta): ?SurfaceContext {
    return $this->context?->withKnown(['delta' => $delta]);
  }

  /**
   * Builds the list definition a list key is advertised as.
   *
   * The shell the owner described, its label, description, required flag
   * and Count, with the child's map as its item: what the parent says
   * about the key, around what the child says about each item.
   *
   * @param \Drupal\Core\TypedData\ListDataDefinition $shell
   *   The list describing the key itself. Not changed; a clone is filled.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child, advertised or refined.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The list.
   */
  public static function listOf(ListDataDefinition $shell, DataSurfaceInterface $child): ListDataDefinition {
    $list = clone $shell;
    $list->setItemDefinition(SurfaceAttachment::mapOf(MapDataDefinition::create(), $child));
    return $list;
  }

  /**
   * Reads the items of a list value, in order.
   *
   * A list is ordered: delta is position, and the keys a sender used are
   * not. Only an integer key is an item: a generated form posts a marker
   * beside its items, under a key no delta can take, and reads its items
   * through this.
   *
   * @param mixed $value
   *   The list value.
   *
   * @return array<int, mixed>
   *   The items, keyed from zero; empty for a value that is no list.
   */
  public static function items(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter($value, static fn (int|string $key): bool => is_int($key), ARRAY_FILTER_USE_KEY));
  }

  /**
   * Answers whether an item holds nothing at all.
   *
   * What a form posts for a row added and left blank: every key of it
   * empty, at any depth. Such an item is dropped before validation, the
   * way core drops an empty delta. An item is never empty because it
   * holds FALSE or 0: those are answers.
   *
   * @param mixed $item
   *   The item, as posted.
   *
   * @return bool
   *   TRUE when it holds nothing.
   */
  public static function isEmptyItem(mixed $item): bool {
    if (!is_array($item)) {
      return !ValueState::isConfigured($item);
    }
    foreach ($item as $value) {
      if (is_array($value) ? !static::isEmptyItem($value) : ValueState::isConfigured($value)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
