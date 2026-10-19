<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * The things a site has, one key each: what a keys provider answers.
 *
 * Each entry is an id, which becomes a key, and a label, which becomes
 * the key's label; it may say what the key starts from, and whether it is
 * locked: shown, held to what is stored, and fixed, as an entry the site
 * no longer lets anyone change. What the list was read from is its
 * cacheability, declared here as on any render array: the cache tags of
 * the configuration or the entities it lists, the contexts it varies by.
 * The list must be cacheable: a provider answering max-age 0 is refused
 * when the resource is built.
 *
 * Entries keep the order they were added in, which is the order the keys
 * are declared, drawn and emitted in.
 *
 * @see \Drupal\data_surface\Resource\KeysFromSiteInterface
 * @see docs/resources.md#keys-that-come-from-the-site
 */
final class KeyedList implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * The entries, by id, in order.
   *
   * @var array<string, array{label: string|\Stringable, default: mixed, locked: bool}>
   */
  protected array $entries = [];

  /**
   * Starts a list from labels by id.
   *
   * @param array<string, string|\Stringable> $labels
   *   Each entry's label, by its id.
   *
   * @return static
   *   The list, cacheable for ever until told otherwise.
   */
  public static function fromLabels(array $labels): static {
    $list = new static();
    foreach ($labels as $id => $label) {
      $list->add((string) $id, $label);
    }
    return $list;
  }

  /**
   * Adds an entry.
   *
   * @param string $id
   *   The key it becomes: no dot, no colon, not empty.
   * @param string|\Stringable $label
   *   The key's label.
   * @param mixed $default
   *   What the key starts from, over what the blueprint declared for every
   *   entry; NULL leaves that.
   * @param bool $locked
   *   Whether the key is shown and held to what is stored, and not
   *   changed.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the id is empty, holds a dot or a colon, or was added already.
   */
  public function add(string $id, string|\Stringable $label, mixed $default = NULL, bool $locked = FALSE): static {
    if ($id === '' || strpbrk($id, '.:') !== FALSE) {
      throw new \InvalidArgumentException(sprintf('"%s" cannot be the id of an entry: it becomes a key, which is not empty and holds no dot or colon.', $id));
    }
    if (isset($this->entries[$id])) {
      throw new \InvalidArgumentException(sprintf('The "%s" entry was added to this list already: one key per thing.', $id));
    }
    $this->entries[$id] = ['label' => $label, 'default' => $default, 'locked' => $locked];
    return $this;
  }

  /**
   * Gets the entries.
   *
   * @return array<string, array{label: string|\Stringable, default: mixed, locked: bool}>
   *   Each entry's label, starting value (NULL for none) and whether it is
   *   locked, by id, in order.
   */
  public function entries(): array {
    return $this->entries;
  }

}
