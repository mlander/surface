<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that narrowing is checked inside maps and list items.
 *
 * A subsurface is advertised as a map whose properties are its child's
 * keys, and what the child narrows arrives in the parent as a narrower
 * map. So the check has to read inside a map: the same properties, each
 * held to the same table, and the one exception a slot's placeholder
 * resolving to the variant its deciding key chose.
 *
 * @see \Drupal\data_surface\Refinement\Narrowing
 */
#[Group('data_surface')]
class NarrowingInsideMapsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A definition reads its type's default constraints from the typed
    // data manager; these types have none.
    $typed_data = $this->createMock(TypedDataManagerInterface::class);
    $typed_data->method('getDefaultConstraints')->willReturn([]);
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $typed_data);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the map every case starts from.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   A map of a bounded integer, a choice, and a list of choices.
   */
  protected static function map(): MapDataDefinition {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('columns', DataDefinition::create('integer')
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 6]));
    $map->setPropertyDefinition('lid', DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['screw', 'clip']]));
    $map->setPropertyDefinition('tags', new ListDataDefinition([], DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['a', 'b', 'c']])));
    return $map;
  }

  /**
   * Tests one change to a map, and the verdict on it.
   *
   * @param string $change
   *   The change, as a method name below.
   * @param string|null $widening
   *   A fragment of the refusal, or NULL when the change narrows.
   */
  #[DataProvider('changes')]
  public function testChangesInsideMap(string $change, ?string $widening): void {
    $before = static::map();
    $after = static::{$change}(DataSurface::deepClone($before));
    if ($widening === NULL) {
      Narrowing::assertNarrows('settings', 'the test', $before, $after);
      $this->addToAssertionCount(1);
      return;
    }
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage($widening);
    Narrowing::assertNarrows('settings', 'the test', $before, $after);
  }

  /**
   * Supplies the changes.
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   The change and the verdict, keyed by case.
   */
  public static function changes(): array {
    return [
      'nothing at all' => ['unchanged', NULL],
      'a property bounded tighter' => ['fewerColumns', NULL],
      'a property offering fewer values' => ['onlyScrew', NULL],
      'a list item offering fewer values' => ['fewerTags', NULL],
      'a property bounded looser' => [
        'moreColumns',
        'inside the columns property, the max of the Range constraint moved from 6 to 9',
      ],
      'a property no longer required' => [
        'optionalColumns',
        'inside the columns property, the required flag was turned off',
      ],
      'a property offering a new value' => [
        'magneticLid',
        'inside the lid property, the Choice constraint gained the values magnet',
      ],
      'a list item offering a new value' => [
        'moreTags',
        'inside the tags property, inside the list items, the Choice constraint gained the values d',
      ],
      'a property retyped' => [
        'stringColumns',
        'inside the columns property, the data type changed from integer to string',
      ],
      'a property added' => ['withExtra', 'the map gained the property extra'],
      'a property removed' => ['withoutLid', 'the map lost the property lid'],
    ];
  }

  /**
   * Tests the one shape refinement may introduce: a slot resolving.
   */
  public function testPlaceholderResolvesToAnyMap(): void {
    $placeholder = DataDefinition::create('any');
    Narrowing::assertNarrows('settings', 'the slot', $placeholder, static::map());
    $this->addToAssertionCount(1);

    // But a required placeholder stays required in what it resolves to.
    $required = DataDefinition::create('any')->setRequired(TRUE);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('the required flag was turned off');
    Narrowing::assertNarrows('settings', 'the slot', $required, static::map());
  }

  /**
   * Gets one property of the map, to change.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map.
   * @param string $name
   *   The property.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The property definition.
   */
  protected static function property(MapDataDefinition $map, string $name): DataDefinition {
    $property = $map->getPropertyDefinition($name);
    assert($property instanceof DataDefinition);
    return $property;
  }

  /**
   * Gets the item definition of the tags list, to change.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The item definition.
   */
  protected static function tagItem(MapDataDefinition $map): DataDefinition {
    $tags = $map->getPropertyDefinition('tags');
    assert($tags instanceof ListDataDefinition);
    $item = $tags->getItemDefinition();
    assert($item instanceof DataDefinition);
    return $item;
  }

  /**
   * Leaves the map alone.
   */
  protected static function unchanged(MapDataDefinition $map): MapDataDefinition {
    return $map;
  }

  /**
   * Bounds the columns tighter.
   */
  protected static function fewerColumns(MapDataDefinition $map): MapDataDefinition {
    static::property($map, 'columns')->addConstraint('Range', ['min' => 2, 'max' => 4]);
    return $map;
  }

  /**
   * Bounds the columns looser.
   */
  protected static function moreColumns(MapDataDefinition $map): MapDataDefinition {
    static::property($map, 'columns')->addConstraint('Range', ['min' => 1, 'max' => 9]);
    return $map;
  }

  /**
   * Turns the columns' required flag off.
   */
  protected static function optionalColumns(MapDataDefinition $map): MapDataDefinition {
    static::property($map, 'columns')->setRequired(FALSE);
    return $map;
  }

  /**
   * Retypes the columns.
   */
  protected static function stringColumns(MapDataDefinition $map): MapDataDefinition {
    $map->setPropertyDefinition('columns', DataDefinition::create('string')->setRequired(TRUE));
    return $map;
  }

  /**
   * Offers one lid.
   */
  protected static function onlyScrew(MapDataDefinition $map): MapDataDefinition {
    static::property($map, 'lid')->addConstraint('Choice', ['choices' => ['screw']]);
    return $map;
  }

  /**
   * Offers a lid nobody advertised.
   */
  protected static function magneticLid(MapDataDefinition $map): MapDataDefinition {
    static::property($map, 'lid')->addConstraint('Choice', ['choices' => ['screw', 'clip', 'magnet']]);
    return $map;
  }

  /**
   * Offers fewer tags.
   */
  protected static function fewerTags(MapDataDefinition $map): MapDataDefinition {
    static::tagItem($map)->addConstraint('Choice', ['choices' => ['a']]);
    return $map;
  }

  /**
   * Offers a tag nobody advertised.
   */
  protected static function moreTags(MapDataDefinition $map): MapDataDefinition {
    static::tagItem($map)->addConstraint('Choice', ['choices' => ['a', 'b', 'c', 'd']]);
    return $map;
  }

  /**
   * Adds a property.
   */
  protected static function withExtra(MapDataDefinition $map): MapDataDefinition {
    $map->setPropertyDefinition('extra', DataDefinition::create('string'));
    return $map;
  }

  /**
   * Removes a property.
   */
  protected static function withoutLid(MapDataDefinition $map): MapDataDefinition {
    $map->setPropertyDefinition('lid', NULL);
    return $map;
  }

}
