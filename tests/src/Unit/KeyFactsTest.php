<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\KeyFacts;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests KeyFacts over a key of each type, a list, a map and `any`.
 */
#[Group('surface')]
class KeyFactsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A definition's constraints ask the typed data manager for its type's
    // defaults: none here.
    $typed = $this->createMock(TypedDataManagerInterface::class);
    $typed->method('getDefaultConstraints')->willReturn([]);
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $typed);
    \Drupal::setContainer($container);
  }

  /**
   * Tests a key of each library type is named by the library's id.
   */
  #[DataProvider('libraryTypes')]
  public function testEachLibraryType(string $id, string $type): void {
    $schema = DefinitionSchema::of(DataDefinition::create($type)->setSetting('widget', 'radios'));
    $this->assertSame($id, KeyFacts::type($schema));
    $this->assertFalse(KeyFacts::isList($schema));
    $this->assertNull(KeyFacts::item($schema));
    $this->assertSame('radios', KeyFacts::setting($schema, 'widget'));
    $this->assertNull(KeyFacts::setting($schema, 'none'));
  }

  /**
   * Gives each library type with the data type it is read into.
   *
   * @return array<string, array{0: string, 1: string}>
   *   The id and the data type, by id.
   */
  public static function libraryTypes(): array {
    $cases = [];
    foreach (LeafTypes::LIBRARY as $id => $type) {
      $cases[$id] = [$id, $type];
    }
    return $cases;
  }

  /**
   * Tests a list answers `array` and gives its item's schema.
   */
  public function testList(): void {
    $list = DefinitionSchema::of((new ListDataDefinition([], DataDefinition::create('integer')))->setSetting('format', TRUE));
    $this->assertSame('array', KeyFacts::type($list));
    $this->assertTrue(KeyFacts::isList($list));
    $this->assertTrue(KeyFacts::setting($list, 'format'));
    $item = KeyFacts::item($list);
    $this->assertNotNull($item);
    $this->assertSame('portable:integer', KeyFacts::type($item));
    $this->assertFalse(KeyFacts::isList($item));
  }

  /**
   * Tests a map answers `object` and is no list.
   */
  public function testMap(): void {
    $map = DefinitionSchema::of(MapDataDefinition::create()->setPropertyDefinition('name', DataDefinition::create('string')));
    $this->assertSame('object', KeyFacts::type($map));
    $this->assertFalse(KeyFacts::isList($map));
    $this->assertNull(KeyFacts::item($map));
    $empty = DefinitionSchema::of(MapDataDefinition::create());
    $this->assertSame('object', KeyFacts::type($empty));
  }

  /**
   * Tests `any`, and a type the library has no id for, answer `any`.
   */
  public function testAny(): void {
    $this->assertSame('any', KeyFacts::type(DefinitionSchema::of(DataDefinition::create('any'))));
    // A data type no row of the library names is never named by its
    // typed data id.
    $this->assertSame('any', KeyFacts::type(DefinitionSchema::of(DataDefinition::create('decimal'))));
  }

}
