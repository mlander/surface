<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Gathers what a data surface depends on, with each variant built since.
 *
 * A sealed data surface says what it read when it was sealed, its children
 * built with it included. A variant the site lists is built when a door
 * reads it, so what that variant read is added by whatever reads it: a
 * caller that caches what it made from a data surface (a form, a tool
 * listing) asks here once it has made it, and is told what it read, the
 * variants it built included, and nothing of a variant it never read.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
final class SettledCacheability {

  /**
   * Gathers the cacheability of a data surface and every child built in it.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   What it and every child of it built so far read.
   */
  public static function of(DataSurfaceInterface $surface): CacheableMetadata {
    $cacheability = CacheableMetadata::createFromObject($surface);
    $seen = [spl_object_id($surface) => TRUE];
    static::gather($surface, $cacheability, $seen);
    return $cacheability;
  }

  /**
   * Adds the cacheability of each child built in one frame, at any depth.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The frame.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Added to.
   * @param array<int, true> $seen
   *   The frames read, by object id.
   */
  protected static function gather(DataSurfaceInterface $surface, CacheableMetadata $cacheability, array &$seen): void {
    $children = [];
    foreach ($surface->getDefinitions()->entries() as $entry) {
      array_push(
        $children,
        ...($entry->attachment === NULL ? [] : [$entry->attachment]),
        ...array_values($entry->mounted),
        ...array_values($entry->variants->variants ?? []),
        ...($entry->list === NULL ? [] : [$entry->list->item]),
      );
    }
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      $children[] = $mixin->structure;
    }
    foreach ($children as $attachment) {
      $child = $attachment->settledChild();
      if ($child === NULL || isset($seen[spl_object_id($child)])) {
        continue;
      }
      $seen[spl_object_id($child)] = TRUE;
      // A child built with its parent is in the parent's already; one
      // built since adds what it read.
      if ($attachment->isDeferred()) {
        $cacheability->addCacheableDependency($child);
      }
      static::gather($child, $cacheability, $seen);
    }
  }

}
