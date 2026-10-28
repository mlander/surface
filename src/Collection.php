<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Resource\OperationContext;

/**
 * A key whose value is a list of child structure instances: a collection.
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
 * The child's operation, and its access, were answered once for the
 * list: field access is per field, not per delta.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::attachList()
 * @see \Drupal\data_surface\Attachment
 */
final class Collection {

  /**
   * Constructs a Collection.
   *
   * @param \Drupal\data_surface\Attachment $item
   *   The child every item is, sealed once, as it was advertised.
   * @param \Drupal\data_surface\Resource\OperationContext|null $context
   *   The context every item was invoked in, the child's operation
   *   resolved for the list; contextFor() adds an item's delta to what
   *   it knows. NULL for a list handed over already sealed.
   */
  public function __construct(
    public readonly Attachment $item,
    public readonly ?OperationContext $context = NULL,
  ) {
  }

  /**
   * Answers whether a definition is a list of maps: drawn as a collection.
   *
   * What attachList() declares, and a list whose item is a map that no
   * collection stands behind (a list declared with a map as its item).
   * The form draws either as one details per item.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a list whose item is a complex definition.
   */
  public static function holdsMaps(DataDefinitionInterface $definition): bool {
    return $definition instanceof ListDataDefinitionInterface && $definition->getItemDefinition() instanceof ComplexDataDefinitionInterface;
  }

  /**
   * Builds the collection a list of maps stands for, where none was attached.
   *
   * A list declared with a map as its item has no child structure behind
   * it, which is what the form draws a collection's items from. This
   * seals one from the item's keys, each starting from its own default:
   * the form's alone, since the list is accepted and validated through
   * its own definition, as any list is.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list.
   *
   * @return self|null
   *   The collection, its context none; NULL for a definition that is no
   *   list of maps.
   */
  public static function forList(DataDefinitionInterface $definition): ?self {
    $item = $definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : NULL;
    if (!$item instanceof ComplexDataDefinitionInterface) {
      return NULL;
    }
    $builder = new DataSurfaceBuilder();
    foreach ($item->getPropertyDefinitions() as $name => $property) {
      $builder->setDefinition((string) $name, $property);
    }
    return new self(new Attachment($builder->seal()));
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
   * whatever reads an item's context (storage, an operation that takes a
   * delta) knows which item it is; nothing stores it.
   *
   * @param int $delta
   *   The item's position, from zero.
   *
   * @return \Drupal\data_surface\Resource\OperationContext|null
   *   The context, or NULL when the list carries none.
   */
  public function contextFor(int $delta): ?OperationContext {
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
    $list->setItemDefinition(Attachment::mapOf(MapDataDefinition::create(), $child));
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
