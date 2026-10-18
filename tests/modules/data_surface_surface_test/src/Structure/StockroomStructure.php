<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A structure with both kinds of refiner, which counts every call.
 *
 * The shelf is narrowed by a static refiner, to the chosen aisle's
 * shelves, and by a runtime one watching the same aisle, which takes away
 * the shelves listed as closed. The tag is narrowed by a runtime refiner
 * that watches nothing, which takes away the banned tags on every
 * refinement. What the runtime refiners read stands in for site state a
 * real one would read from a service: two static lists a test sets.
 */
#[Structure('surface_test.stockroom')]
final class StockroomStructure implements StructureInterface {

  /**
   * The shelves, by aisle.
   */
  public const SHELVES = [
    'north' => ['north_1' => 'North one', 'north_2' => 'North two'],
    'south' => ['south_1' => 'South one', 'south_2' => 'South two'],
  ];

  /**
   * The tags.
   */
  public const TAGS = ['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'];

  /**
   * How often each refiner was called, by method name.
   *
   * @var array<string, int>
   */
  public static array $calls = [];

  /**
   * The shelves the runtime refiner takes away.
   *
   * @var string[]
   */
  public static array $closed = [];

  /**
   * The tags the runtime refiner that watches nothing takes away.
   *
   * @var string[]
   */
  public static array $banned = [];

  /**
   * Reads how often each refiner was called since the counts were reset.
   *
   * @return array<string, int>
   *   The calls, by method name.
   *
   * @phpstan-impure
   */
  public static function calls(): array {
    return self::$calls;
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('aisle', 'string', 'Aisle')
      ->addConstraint('LabeledChoice', ['choices' => ['north' => 'North', 'south' => 'South']]);
    $inputs->add('shelf', 'string', 'Shelf')
      ->addConstraint('LabeledChoice', ['choices' => array_merge(...array_values(self::SHELVES))]);
    $inputs->add('tag', 'string', 'Tag')
      ->addConstraint('LabeledChoice', ['choices' => self::TAGS]);
  }

  /**
   * The shelf is one of the aisle's: static, so it can be listed.
   */
  #[RefinesInput('shelf')]
  public static function shelfInAisle(DataDefinitionInterface $shelf, string $aisle): DataDefinitionInterface {
    self::$calls[__FUNCTION__] = (self::$calls[__FUNCTION__] ?? 0) + 1;
    return $shelf->addConstraint('LabeledChoice', ['choices' => self::SHELVES[$aisle] ?? []]);
  }

  /**
   * No closed shelf: only the runtime knows which are closed.
   */
  #[RefinesInput('shelf', runtime: TRUE)]
  public static function openShelf(DataDefinitionInterface $shelf, string $aisle): DataDefinitionInterface {
    self::$calls[__FUNCTION__] = (self::$calls[__FUNCTION__] ?? 0) + 1;
    $choices = $shelf->getConstraints()['LabeledChoice']['choices'] ?? [];
    return $shelf->addConstraint('LabeledChoice', ['choices' => array_diff_key($choices, array_flip(self::$closed))]);
  }

  /**
   * No banned tag, whatever else is answered.
   */
  #[RefinesInput('tag', runtime: TRUE)]
  public static function allowedTag(DataDefinitionInterface $tag): DataDefinitionInterface {
    self::$calls[__FUNCTION__] = (self::$calls[__FUNCTION__] ?? 0) + 1;
    return $tag->addConstraint('LabeledChoice', ['choices' => array_diff_key(self::TAGS, array_flip(self::$banned))]);
  }

}
