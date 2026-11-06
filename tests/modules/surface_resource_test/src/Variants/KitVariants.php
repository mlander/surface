<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants;

use Drupal\surface\Resource\BoundSiteListInterface;
use Drupal\surface\Resource\SiteList;

/**
 * Lists the structures its owner names, one variant per value.
 *
 * Bound to pairs, each a value and the structure class it chooses, so a
 * fixture names the variants it mixes in without a #[Variant] of its own.
 */
final class KitVariants implements BoundSiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return new SiteList();
  }

  /**
   * {@inheritdoc}
   *
   * @param list<string|int> $arguments
   *   A value, then the structure class it chooses, for each variant.
   */
  public function listFor(array $arguments): SiteList {
    $list = new SiteList();
    foreach (array_chunk($arguments, 2) as [$value, $structure]) {
      $list->add((string) $value, (string) $value, structure: (string) $structure);
    }
    return $list;
  }

}
