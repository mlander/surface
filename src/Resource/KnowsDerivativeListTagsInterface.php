<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A deriver that says what decides which derivatives there are.
 *
 * Discovery is cached as an index (which resources and structures there
 * are, by name and id) and one entry per definition. Each derivative's
 * entry carries the cache tags of what it was read from
 * (DerivedResource::$cacheTags), and only those: one field saved clears
 * that field's structure and its bundle's resource, and nothing else. The
 * index carries the tags of what decides which derivatives exist at all,
 * which no single derivative can name (a bundle that does not exist yet):
 * this method's answer. A deriver that does not implement it has its
 * index tagged with every tag its derivatives carry, which is honest and
 * wider.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 *
 * @see \Drupal\data_surface\Resource\ResourceDeriverInterface
 */
interface KnowsDerivativeListTagsInterface {

  /**
   * Gets the tags of the facts that decide which derivatives there are.
   *
   * @return string[]
   *   The cache tags: invalidating one reads the list again (a bundle
   *   created, a form display saved), and each derivative whose own tags
   *   did not fire is kept as it was cached.
   */
  public function listCacheTags(): array;

}
