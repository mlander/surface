<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;

/**
 * A runtime refiner for DoorSpecificRuleTest: the current user is flagged.
 */
final class DoorSpecificFixture {

  /**
   * Reads the current user: flagged; State is site state: not flagged.
   */
  #[RefinesInput('room', runtime: TRUE)]
  public static function readsTheCurrentUser(DataDefinitionInterface $room): DataDefinitionInterface {
    return \Drupal::currentUser()->isAnonymous() || \Drupal::state()->get('closed') ? $room : $room->setRequired(TRUE);
  }

}
