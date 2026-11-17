<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\surface\Resource\Attribute\RefinesInput;
use PortableSchema\Builder\Schema;

/**
 * Refiners for StaticRefinerRuleTest: which the rule flags, and which not.
 */
final class StaticRefinerFixture {

  /**
   * Static, and reads State: flagged.
   */
  #[RefinesInput('room')]
  public static function readsState(): Schema {
    return \Drupal::state()->get('closed') ? Schema::any() : Schema::any()->required();
  }

  /**
   * Static, and asks the container for a service: flagged.
   */
  #[RefinesInput('room')]
  public static function asksForService(): Schema {
    \Drupal::service('surface.options');
    return Schema::any();
  }

  /**
   * Runtime by declaration: not flagged.
   */
  #[RefinesInput('room', runtime: TRUE)]
  public static function runtime(): Schema {
    return \Drupal::currentUser()->isAnonymous() ? Schema::any() : Schema::any()->required();
  }

  /**
   * Pure: not flagged.
   */
  #[RefinesInput('room')]
  public static function pure(string $venue): Schema {
    return $venue === 'hall' ? Schema::any()->required() : Schema::any();
  }

  /**
   * Not a refiner at all: not flagged.
   */
  public static function helper(): bool {
    return \Drupal::hasContainer();
  }

}
