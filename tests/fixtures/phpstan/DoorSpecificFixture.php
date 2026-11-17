<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\surface\Resource\Attribute\RefinesInput;
use PortableSchema\Builder\Schema;

/**
 * A runtime refiner for DoorSpecificRuleTest: the current user is flagged.
 */
final class DoorSpecificFixture {

  /**
   * Reads the current user: flagged; State is site state: not flagged.
   */
  #[RefinesInput('room', runtime: TRUE)]
  public static function readsTheCurrentUser(): Schema {
    return \Drupal::currentUser()->isAnonymous() || \Drupal::state()->get('closed') ? Schema::any() : Schema::any()->required();
  }

}
