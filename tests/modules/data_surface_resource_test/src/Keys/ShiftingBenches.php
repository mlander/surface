<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * A list that leaves its last entry out every other time it is asked.
 *
 * So two maps it lists in one build disagree: the first asked has one
 * entry fewer than the second.
 */
final class ShiftingBenches implements SiteListInterface {

  /**
   * How many times a list was asked for.
   */
  protected static int $asked = 0;

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $keys = (new SiteList())->add('lathe', 'Lathe');
    if (++static::$asked % 2 === 0) {
      $keys->add('press', 'Drill press');
    }
    return $keys;
  }

}
