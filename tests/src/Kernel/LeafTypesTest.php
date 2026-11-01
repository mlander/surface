<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\SurfaceBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the one table of leaf types the blueprint, emitter and reader read.
 *
 * Every key's value is of a type the contract states a JSON Schema type
 * for: add() refuses any other, naming the method for what was meant,
 * addDefinition() holds every value inside a map or a list to it, and the
 * emitter and the reader write and read the same rows.
 *
 * @see docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LeafTypesTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * Builds a blueprint over a builder, with config schema types known.
   *
   * @param \Drupal\surface\SurfaceBuilder $builder
   *   The builder it fills.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(SurfaceBuilder $builder = new SurfaceBuilder()): ResourceBlueprint {
    $shapes = new Shapes($this->container->get('surface.resource_registry'), $this->container->get('class_resolver'), $this->container->get('config.typed'));
    return (new ResourceBlueprint($builder, $this->container->get('typed_data_manager')))
      ->useShapes($shapes)
      ->useReferences($this->container->get('surface.references'));
  }

  /**
   * Asserts a call is refused with words its message holds.
   *
   * @param callable $call
   *   The call.
   * @param string ...$words
   *   What the message says.
   */
  protected function assertRefused(callable $call, string ...$words): void {
    try {
      $call();
      $this->fail('Refused: ' . $words[0]);
    }
    catch (\LogicException $e) {
      foreach ($words as $said) {
        $this->assertStringContainsString($said, $e->getMessage());
      }
    }
  }

  /**
   * Tests every row is a key add() declares, written as the row says.
   */
  public function testEveryRowIsDeclaredAndWritten(): void {
    $builder = new SurfaceBuilder();
    $blueprint = $this->blueprint($builder);
    foreach (array_keys(LeafTypes::ROWS) as $type) {
      $this->assertSame($type, $blueprint->add($type, $type, $type)->getDataType());
    }
    $schema = $this->container->get('surface.contract_emitter')->emitFull($builder->seal(), 'test')->document['schema'];
    foreach (LeafTypes::ROWS as $type => $row) {
      $written = array_intersect_key($schema['properties'][$type], ['type' => TRUE, 'format' => TRUE]);
      $written['type'] = array_values(array_diff((array) $written['type'], ['null']))[0];
      $this->assertSame($row, $written, $type);
      $this->assertSame($type === 'timestamp' ? 'integer' : $type, LeafTypes::read($row['type'], $row['format'] ?? NULL), 'Read back to its row, the first that writes it.');
    }
    $this->assertSame('email', LeafTypes::read('string', 'email'), 'Read as Drupal\'s email, written back as idn-email.');
    $this->assertSame('string', LeafTypes::read('string', 'date'), 'A format no row writes reads as the string.');
  }

  /**
   * Tests add() refuses a type no row is for, naming what was meant.
   */
  public function testTypeNoRowIsForIsRefused(): void {
    $blueprint = $this->blueprint();
    $said = 'which JSON Schema has no type for. add() takes one of string, email, uri, datetime_iso8601, integer, timestamp, float, boolean';
    $this->assertRefused(fn () => $blueprint->add('logo', 'binary', 'Logo'), 'add("logo", "binary") declares a key of the binary type, ' . $said, 'a typed data definition built by hand is addDefinition()');
    $this->assertRefused(fn () => $blueprint->add('length', 'duration_iso8601', 'Length'), 'the duration_iso8601 type');
    $this->assertRefused(fn () => $blueprint->add('language', 'language', 'Language'), 'the language type');
    $this->assertRefused(fn () => $blueprint->add('settings', 'map', 'Settings'), 'a group of keys is attach(\'<key>\', <structure>::class), or addMap() for keys only this one holds.');
    $this->assertRefused(fn () => $blueprint->add('tags', 'list', 'Tags'), 'a list is addList().');
    $this->assertRefused(fn () => $blueprint->add('page', 'entity:node', 'Page'), "an id of an entity is refer('<key>', '<entity type>', ...).");
    $this->assertRefused(fn () => $blueprint->add('detail', 'any', 'Detail'), 'a value whose shape depends on another key is variants, attachVariants().');
    $this->assertRefused(fn () => $blueprint->addList('logos', 'binary', 'Logos'), 'addList("logos", "binary") declares items of the binary type, which JSON Schema has no type for.');
  }

  /**
   * Tests addDefinition() holds every value inside to the table.
   */
  public function testDefinitionIsWalked(): void {
    $blueprint = $this->blueprint();
    $map = MapDataDefinition::create()
      ->setPropertyDefinition('name', DataDefinition::create('string'))
      ->setPropertyDefinition('logo', DataDefinition::create('binary'));
    $this->assertRefused(fn () => $blueprint->addDefinition('brand', $map), 'addDefinition("brand") declares "logo" of the binary type, which JSON Schema has no type for.');
    $list = new ListDataDefinition(['type' => 'list'], MapDataDefinition::create()->setPropertyDefinition('spoken', DataDefinition::create('language')));
    $this->assertRefused(fn () => $blueprint->addDefinition('people', $list), 'addDefinition("people") declares "*.spoken" of the language type');
    $this->assertRefused(fn () => $blueprint->addDefinition('detail', DataDefinition::create('any')), 'addDefinition("detail") declares a value of the any type');

    // The two `any` the module makes are held: the variants' placeholder,
    // and a value a schema read holds whole.
    $placeholder = DataDefinition::create('any');
    DefinitionMetadata::setVariantsBy($placeholder, 'kind');
    $read = DataDefinition::create('any');
    DefinitionMetadata::setHeldWhole($read);
    $blueprint->addDefinition('chosen', $placeholder);
    $blueprint->addDefinition('read', $read);
    $this->assertSame(['chosen', 'read'], $blueprint->keys());
  }

  /**
   * Tests the config schema types the examples use resolve to a row.
   */
  public function testConfigSchemaTypesResolveToRow(): void {
    $blueprint = $this->blueprint();
    foreach (['machine_name', 'label', 'path', 'color_hex', 'langcode', 'required_label', 'uri'] as $type) {
      $definition = $blueprint->add($type, $type, $type);
      $this->assertTrue(LeafTypes::has($definition->getDataType()), $type . ' is ' . $definition->getDataType());
    }
  }

  /**
   * Tests the emitter refuses a value no row is for, handed it by hand.
   */
  public function testTheEmitterRefusesWhatNoRowIsFor(): void {
    $surface = (new SurfaceBuilder())->setDefinition('logo', DataDefinition::create('binary')->setLabel('Logo'))->seal();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('"Logo" is of the binary type, which no JSON Schema type is written for');
    $this->container->get('surface.contract_emitter')->emitFull($surface, 'test');
  }

}
