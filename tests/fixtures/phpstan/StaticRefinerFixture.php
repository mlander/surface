<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;

/**
 * Refiners for StaticRefinerRuleTest: which the rule flags, and which not.
 */
final class StaticRefinerFixture {

  /**
   * Static, and reads State: flagged.
   */
  #[RefinesInput('room')]
  public static function readsState(DataDefinitionInterface $room): DataDefinitionInterface {
    return \Drupal::state()->get('closed') ? $room : $room->setRequired(TRUE);
  }

  /**
   * Static, and asks the container for a service: flagged.
   */
  #[RefinesInput('room')]
  public static function asksForService(DataDefinitionInterface $room): DataDefinitionInterface {
    \Drupal::service('surface.options');
    return $room;
  }

  /**
   * Runtime by declaration: not flagged.
   */
  #[RefinesInput('room', runtime: TRUE)]
  public static function runtime(DataDefinitionInterface $room): DataDefinitionInterface {
    return \Drupal::currentUser()->isAnonymous() ? $room : $room->setRequired(TRUE);
  }

  /**
   * Pure: not flagged.
   */
  #[RefinesInput('room')]
  public static function pure(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $venue === 'hall' ? $room->setRequired(TRUE) : $room;
  }

  /**
   * Not a refiner at all: not flagged.
   */
  public static function helper(): bool {
    return \Drupal::hasContainer();
  }

}
