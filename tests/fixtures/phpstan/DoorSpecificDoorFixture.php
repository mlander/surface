<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Fixtures;

/**
 * Nothing the pipeline runs: not flagged.
 */
final class DoorSpecificDoorFixture {

  /**
   * A door reads the request: that is its job.
   */
  public static function path(): string {
    return \Drupal::request()->getPathInfo();
  }

}
