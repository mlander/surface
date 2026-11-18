<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\surface\ResourceBuild\ShapeBlueprint;
use Drupal\surface_resource_test\Resource\Library\OpeningHoursResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a list declared with addDefinition(), the long form.
 *
 * The one list no schema states, a list whose item is a plain map, is
 * handed in as a list definition: labeled and defaulted by the caller,
 * held to the type table as add() holds a key. The opening hours declare
 * their three lists with add().
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AddDefinitionListTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'surface',
    'surface_resource_test',
  ];

  /**
   * Gets a fresh declaring class's blueprint over a fresh builder.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(): ResourceBlueprint {
    return new ResourceBlueprint(new SurfaceBuilder(), $this->container->get('typed_data_manager'));
  }

  /**
   * Tests a list definition is the key, as it was handed in.
   */
  public function testTheListIsTheKey(): void {
    $builder = new SurfaceBuilder();
    $inputs = new ResourceBlueprint($builder, $this->container->get('typed_data_manager'));

    $slot = MapDataDefinition::create()
      ->setPropertyDefinition('at', DataDefinition::create('datetime_iso8601')->setLabel('At'));
    $slots = (new ListDataDefinition(['type' => 'list'], $slot))->setLabel('Slots');
    $this->assertSame($slots, $inputs->addDefinition('slots', $slots, default: [])->shellAt('slots'));
    $this->assertSame('list', $slots->getDataType());
    $this->assertSame($slot, $slots->getItemDefinition(), 'An item definition is the item.');
    $this->assertSame([], DefinitionMetadata::getDefaultValue($slots));
    $this->assertSame($slots, $builder->getDefinition('slots'), 'The list is part of the blueprint.');

    $days = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))->setLabel('Days');
    $inputs->addDefinition('days', $days);
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($days), 'A NULL default declares none.');
  }

  /**
   * Tests an alter's blueprint adds a list at its own mount.
   */
  public function testAlterAddsList(): void {
    $builder = new SurfaceBuilder();
    $alter = new ResourceBlueprintAdditions($builder, $this->container->get('typed_data_manager'), 'first_module');
    $tags = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))->setLabel('Tags');
    $alter->addDefinition('tags', $tags, default: ['a']);
    $this->assertSame($tags, $builder->getThirdPartyDefinition('first_module', 'tags'));
    $this->assertSame(['a'], DefinitionMetadata::getDefaultValue($tags));
  }

  /**
   * Tests what a list handed to addDefinition() is refused for.
   */
  public function testWhatItRefuses(): void {
    $list = static fn (): ListDataDefinition => (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))->setLabel('Tags');
    try {
      $inputs = $this->blueprint();
      $inputs->add('tags', Schema::string()->title('Tags'));
      $inputs->addDefinition('tags', $list());
      $this->fail('A key added twice was accepted.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('The input "tags" was already added to this blueprint.', $e->getMessage());
    }

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('addDefinition("tags")');
    (new ShapeBlueprint('resource_test.sku', 'SomeAlter'))->addDefinition('tags', $list());
  }

  /**
   * Tests the opening hours' lists, as the how-to page quotes them.
   *
   * Declared into a blueprint of their own: what the lists are is the
   * declaration's, and HowToTest holds the built resource to the page.
   */
  public function testTheHowToLists(): void {
    $builder = new SurfaceBuilder();
    OpeningHoursResource::defineInputs(new ResourceBlueprint($builder, $this->container->get('typed_data_manager')));

    $days = $builder->getDefinition('days');
    $this->assertInstanceOf(ListDataDefinition::class, $days);
    $this->assertSame('Days open', (string) $days->getLabel());
    $this->assertTrue($days->isRequired());
    $this->assertArrayHasKey('LabeledChoice', $days->getItemDefinition()->getConstraints());
    $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], DefinitionMetadata::getDefaultValue($days));

    $late = $builder->getDefinition('late_days');
    $this->assertInstanceOf(ListDataDefinition::class, $late);
    $this->assertEquals($days->getItemDefinition(), $late->getItemDefinition(), 'One item, two lists.');
    $this->assertSame(1, $late->getConstraints()['Count']['min']);

    $keywords = $builder->getDefinition('keywords');
    $this->assertInstanceOf(ListDataDefinition::class, $keywords);
    $this->assertSame('string', $keywords->getItemDefinition()->getDataType());
    $this->assertSame([], DefinitionMetadata::getDefaultValue($keywords));
  }

}
