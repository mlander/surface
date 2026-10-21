<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\data_surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\data_surface\ResourceBuild\ShapeBlueprint;
use Drupal\data_surface_resource_test\Resource\Library\OpeningHoursResource;
use Drupal\data_surface_resource_test\Shape\Sku;
use Drupal\data_surface_resource_test\Structure\Workshop\BenchStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests addList(): a list of values is declared as add() declares a key.
 *
 * The item is a type name, a shape's class or a definition; the list is
 * built around it, labeled and defaulted, and returned for the core
 * setters. The opening hours declare their three lists with it.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class AddListTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * Gets a fresh owner's blueprint over a fresh builder.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(): ResourceBlueprint {
    return new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'));
  }

  /**
   * Tests each kind of item, and what the list carries.
   */
  public function testEachKindOfItem(): void {
    $builder = new DataSurfaceBuilder();
    $inputs = new ResourceBlueprint($builder, $this->container->get('typed_data_manager'));

    $keywords = $inputs->addList('keywords', 'string', 'Keywords', default: []);
    $this->assertInstanceOf(ListDataDefinition::class, $keywords);
    $this->assertSame('list', $keywords->getDataType());
    $this->assertSame('string', $keywords->getItemDefinition()->getDataType());
    $this->assertSame('Keywords', (string) $keywords->getLabel());
    $this->assertSame([], DefinitionMetadata::getDefaultValue($keywords));
    $this->assertSame($keywords, $builder->getDefinition('keywords'), 'The list is part of the blueprint.');

    $day = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['mon', 'tue']]);
    $days = $inputs->addList('days', $day, 'Days')->setRequired(TRUE);
    $this->assertSame($day, $days->getItemDefinition(), 'An item definition is the item.');
    $this->assertTrue($days->isRequired());
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($days), 'A NULL default declares none.');

    $codes = $inputs->addList('codes', Sku::class, 'Codes');
    $this->assertSame('resource_test.sku', DefinitionMetadata::shapeOf($codes->getItemDefinition()), 'A shape\'s class makes every item the shape.');
    $this->assertArrayHasKey('Regex', $codes->getItemDefinition()->getConstraints());
    $this->assertArrayHasKey('codes.*', $inputs->shapedKeys(), 'The item is held to the shape as a key declared with it is.');
  }

  /**
   * Tests an alter's blueprint adds a list at its own mount.
   */
  public function testAlterAddsList(): void {
    $builder = new DataSurfaceBuilder();
    $alter = new ResourceBlueprintAdditions($builder, $this->container->get('typed_data_manager'), 'first_module');
    $tags = $alter->addList('tags', 'string', 'Tags', default: ['a']);
    $this->assertSame($tags, $builder->getThirdPartyDefinition('first_module', 'tags'));
    $this->assertSame(['a'], DefinitionMetadata::getDefaultValue($tags));
  }

  /**
   * Tests what addList() refuses.
   */
  public function testWhatItRefuses(): void {
    $refusals = [
      'a key added twice' => [
        static function (ResourceBlueprint $inputs): void {
          $inputs->add('tags', 'string', 'Tags');
          $inputs->addList('tags', 'string', 'Tags');
        },
        'The input "tags" was already added to this blueprint.',
      ],
      'a structure as its item' => [
        static fn (ResourceBlueprint $inputs) => $inputs->addList('benches', BenchStructure::class, 'Benches'),
        'addList("benches") was handed ' . BenchStructure::class . ', a resource or structure, as its item: a list of groups of keys is a collection, attachList(\'benches\', ...).',
      ],
      'a class that is no shape' => [
        static fn (ResourceBlueprint $inputs) => $inputs->addList('rows', OpeningHoursResource::class . 'Missing', 'Rows'),
        'addList("rows") was handed ' . OpeningHoursResource::class . 'Missing, which is no typed data type and no shape',
      ],
    ];
    foreach ($refusals as $label => [$call, $message]) {
      try {
        $call($this->blueprint());
        $this->fail($label . ' was accepted.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $label);
      }
    }

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('addList("tags")');
    (new ShapeBlueprint('resource_test.sku', 'SomeAlter'))->addList('tags', 'string', 'Tags');
  }

  /**
   * Tests the opening hours' lists, as the recipes page quotes them.
   *
   * Declared into a blueprint of their own: what the lists are is the
   * declaration's, and RecipesTest holds the built resource to the page.
   */
  public function testTheRecipesLists(): void {
    $builder = new DataSurfaceBuilder();
    OpeningHoursResource::defineInputs(new ResourceBlueprint($builder, $this->container->get('typed_data_manager')));

    $days = $builder->getDefinition('days');
    $this->assertInstanceOf(ListDataDefinition::class, $days);
    $this->assertSame('Days open', (string) $days->getLabel());
    $this->assertTrue($days->isRequired());
    $this->assertArrayHasKey('LabeledChoice', $days->getItemDefinition()->getConstraints());
    $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], DefinitionMetadata::getDefaultValue($days));

    $late = $builder->getDefinition('late_days');
    $this->assertInstanceOf(ListDataDefinition::class, $late);
    $this->assertSame($days->getItemDefinition(), $late->getItemDefinition(), 'One item, two lists.');
    $this->assertSame(1, $late->getConstraints()['Count']['min']);

    $keywords = $builder->getDefinition('keywords');
    $this->assertInstanceOf(ListDataDefinition::class, $keywords);
    $this->assertSame('string', $keywords->getItemDefinition()->getDataType());
    $this->assertSame([], DefinitionMetadata::getDefaultValue($keywords));
  }

}
