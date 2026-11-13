<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\SurfaceInterface;

/**
 * Refines a level against its values once for the object that uses it.
 *
 * A reader that looks up several places of one data surface for one set
 * of values (Location::definitionAt()) hands refine() as what refines a
 * level, so each level is refined once and its entries, with the nodes
 * they have read (Entry::schema()), serve every lookup. A refine makes
 * new entries, which have read nothing; a reader that refined per lookup
 * would read each node again per lookup.
 *
 * Decision: see
 * docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone.
 *
 * @internal
 */
trait KeepsRefines {

  /**
   * Each level refined, by the level and its values.
   *
   * @var array<string, array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\SurfaceInterface}>
   */
  private array $refined = [];

  /**
   * Refines a level against its values, once for this object.
   *
   * @param \Drupal\surface\SurfaceInterface $level
   *   The level.
   * @param array $values
   *   Its values.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   It, refined.
   */
  private function refine(SurfaceInterface $level, array $values): SurfaceInterface {
    $key = spl_object_id($level) . ':' . hash('xxh128', serialize($values));
    if (!isset($this->refined[$key]) || $this->refined[$key][0] !== $level) {
      $this->refined[$key] = [$level, $level->refine($values)];
    }
    return $this->refined[$key][1];
  }

}
