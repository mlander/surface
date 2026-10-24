<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Variants;

/**
 * How many entries of a list the site decides a document spells out.
 *
 * Two kinds of key take their list from the site, not the code: variants
 * from the site (`attachVariants(from:)`, and the variants inside a
 * structure read from a schema, a component in a slot), and keys from the
 * site (`addEach()`, `attachEach()`). Each is enumerated into the full
 * schema and the tool listing while its list has at most
 * `data_surface.settings:site_list_limit` entries. Past it, the full
 * schema states the key open and marks it `refine`, and the tool listing
 * names the narrowing tool; the chooser's own options are listed whatever
 * the count, since they are one id and one label each. Each key is counted
 * at its own level: a component in a slot counts the components it may be,
 * not the ones above it.
 *
 * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
 */
final class SiteListBudget {

  /**
   * The configuration object the limit lives in.
   */
  public const SETTINGS = 'data_surface.settings';

  /**
   * The setting: the most entries of a site's list a document spells out.
   */
  public const LIMIT = 'site_list_limit';

  /**
   * The limit when the setting is not there.
   */
  public const DEFAULT_LIMIT = 20;

  /**
   * The limit a caller holds for the rest of a call (withLimit()), if any.
   */
  protected ?int $held = NULL;

  /**
   * Constructs the budget.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, for the limit.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the most entries of a site's list a document spells out.
   *
   * @return int
   *   The setting, or DEFAULT_LIMIT when the site has none; while
   *   withLimit() runs, the limit it holds.
   */
  public function limit(): int {
    if ($this->held !== NULL) {
      return $this->held;
    }
    $limit = $this->configFactory->get(self::SETTINGS)->get(self::LIMIT);
    return is_numeric($limit) ? max(0, (int) $limit) : self::DEFAULT_LIMIT;
  }

  /**
   * Holds another limit for one call, saving nothing.
   *
   * @param int $limit
   *   The limit.
   * @param callable(): T $call
   *   What runs with it.
   *
   * @return T
   *   What the call answered.
   *
   * @template T
   */
  public function withLimit(int $limit, callable $call): mixed {
    $was = $this->held;
    $this->held = max(0, $limit);
    try {
      return $call();
    }
    finally {
      $this->held = $was;
    }
  }

  /**
   * Gets what whether a list is spelled out depends on: the setting.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The settings' cacheability.
   */
  public function cacheability(): CacheableMetadata {
    return CacheableMetadata::createFromObject($this->configFactory->get(self::SETTINGS));
  }

  /**
   * Answers whether a key's list is the site's.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   *
   * @return bool
   *   TRUE for variants the site lists, and a map of keys from the site.
   */
  public static function listedBySite(Entry $entry): bool {
    if ($entry->variants !== NULL) {
      return DefinitionMetadata::variantsListedBySite($entry->variants->shell);
    }
    return $entry->attachment !== NULL && DefinitionMetadata::eachOf($entry->definition) !== NULL;
  }

  /**
   * Answers whether a key's list is the site's and longer than the limit.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects the setting's cacheability, for a key whose list is the
   *   site's, whatever its count.
   *
   * @return bool
   *   TRUE when the documents state the key open and the listing names
   *   the narrowing tool.
   */
  public function over(Entry $entry, ?CacheableMetadata $cacheability = NULL): bool {
    if (!static::listedBySite($entry)) {
      return FALSE;
    }
    $cacheability?->addCacheableDependency($this->cacheability());
    return static::count($entry) > $this->limit();
  }

  /**
   * Answers whether a key with variants is the site's and over the limit.
   *
   * @param \Drupal\data_surface\Variants $variants
   *   The variants.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects the setting's cacheability, as over() does.
   *
   * @return bool
   *   TRUE when the documents state the key open.
   */
  public function variantsOver(Variants $variants, ?CacheableMetadata $cacheability = NULL): bool {
    if (!DefinitionMetadata::variantsListedBySite($variants->shell)) {
      return FALSE;
    }
    $cacheability?->addCacheableDependency($this->cacheability());
    return count($variants->variants) > $this->limit();
  }

  /**
   * Answers whether any key, at any depth, is the site's and over the limit.
   *
   * Walks attached children, collections' items and every variant; a key
   * over the limit answers at once, and a child met twice is read once.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The built resource or structure.
   *
   * @return bool
   *   TRUE when its listing has a key marked `refine` for its count, so
   *   the operation has a narrowing tool.
   */
  public function anyOver(DataSurfaceInterface $surface): bool {
    $seen = [];
    return $this->overIn($surface, $seen);
  }

  /**
   * Walks one frame for anyOver().
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The frame.
   * @param array<int, true> $seen
   *   The frames read, by object id.
   *
   * @return bool
   *   TRUE when a key in it or below it is over the limit.
   */
  protected function overIn(DataSurfaceInterface $surface, array &$seen): bool {
    if (isset($seen[spl_object_id($surface)])) {
      return FALSE;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      if ($this->over($entry)) {
        return TRUE;
      }
      $children = [
        ...($entry->attachment === NULL ? [] : [$entry->attachment->child]),
        ...($entry->list === NULL ? [] : [$entry->list->child()]),
        ...array_map(static fn ($variant): DataSurfaceInterface => $variant->child, array_values($entry->variants->variants ?? [])),
      ];
      foreach ($children as $child) {
        if ($this->overIn($child, $seen)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Counts a key's entries: its variants, or the keys of its map.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   A key whose list is the site's.
   *
   * @return int
   *   The count.
   */
  protected static function count(Entry $entry): int {
    if ($entry->variants !== NULL) {
      return count($entry->variants->variants);
    }
    return $entry->attachment === NULL ? 0 : count($entry->attachment->child->getDefinitions()->names());
  }

}
