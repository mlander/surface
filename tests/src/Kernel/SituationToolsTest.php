<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_demo_node_type\Target\NodeTypeTarget;
use Drupal\data_surface_surface_test\Surface\PinnedNoteSurface;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tools generated from situations.
 *
 * One tool per situation of every discovered surface that names a
 * target, `data_surface:<surface id>:<situation id>`, its inputs the
 * situation's parameters, the surface's keys the situation does not
 * know as `values`, and `dry_run`. No tool class names a surface.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SituationToolsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_address',
    'data_surface_demo_node_type',
    'data_surface_tool',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['field', 'node']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Creates one tool.
   *
   * @param string $id
   *   The plugin id.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool.
   */
  protected function tool(string $id): ToolInterface {
    return $this->container->get('plugin.manager.tool')->createInstance($id);
  }

  /**
   * Tests that every surface with a target has one tool per situation.
   */
  public function testSurfacesWithTargetsHaveOneToolPerSituation(): void {
    $ids = array_keys(array_filter(
      $this->container->get('plugin.manager.tool')->getDefinitions(),
      static fn (string $id): bool => str_starts_with($id, 'data_surface:') && substr_count($id, ':') === 2,
      ARRAY_FILTER_USE_KEY,
    ));
    sort($ids);
    $this->assertSame([
      'data_surface:field.instance:add',
      'data_surface:field.instance:edit',
      'data_surface:field.instance:reuse',
      'data_surface:field.storage:edit',
      'data_surface:node.type:add',
      'data_surface:node.type:edit',
    ], $ids);
    // The address settings name a target and have no situation of their
    // own: they are only ever a field's.
    $this->assertArrayNotHasKey('data_surface:field.settings.address:add', $this->container->get('plugin.manager.tool')->getDefinitions());
  }

  /**
   * Tests the first derivation rule: a permission nothing can name.
   *
   * The field storage's add situation needs nothing, and its permission
   * names %entity_type_id, which no parameter supplies: nothing a caller
   * sends could name the permission, so it could never be allowed, and
   * it is no tool. Its edit takes the storage, an entity whose situation
   * knows the entity type, so it is one.
   */
  public function testUnnameablePermissionIsNoTool(): void {
    $registry = $this->container->get('data_surface.surface_registry');
    $this->assertSame(['entity_type_id'], $registry->getSituation('field.storage', 'add')->unresolvablePlaceholders());
    $this->assertSame([], $registry->getSituation('field.storage', 'edit')->unresolvablePlaceholders());
    $this->assertSame([], $registry->getSituation('field.instance', 'add')->unresolvablePlaceholders());
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $this->assertArrayNotHasKey('data_surface:field.storage:add', $definitions);
    $this->assertArrayHasKey('data_surface:field.storage:edit', $definitions);
  }

  /**
   * Tests the second derivation rule: a plugin's surface is no tool.
   *
   * The pinned note surface names a target and a situation of its own,
   * and a block names it with #[UsesSurface], so it is that block's
   * configuration and is configured through the block host. A surface
   * with a target that no plugin uses, beside it, is a tool.
   */
  public function testPluginSurfaceIsNoTool(): void {
    $this->enableModules(['data_surface_surface_test']);
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $this->assertSame(['block:data_surface_surface_test_pinned_note'], $this->container->get('data_surface.surface_plugins')->usedBy(PinnedNoteSurface::class));
    $this->assertArrayNotHasKey('data_surface:test.pinned_note:pin', $definitions);
    $this->assertArrayHasKey('data_surface:surface_test.pantry:add', $definitions);
  }

  /**
   * Tests what the tools advertise: parameters, values, dry run.
   */
  public function testInputsAreTheParametersThenTheOpenKeys(): void {
    $manager = $this->container->get('plugin.manager.tool');

    // Adding a content type needs nothing, so the values input is the
    // add surface itself, machine name included and unique.
    $add = $manager->getDefinition('data_surface:node.type:add');
    $this->assertSame('Add a content type', (string) $add->getLabel());
    $this->assertSame([SituationInputs::VALUES, SituationInputs::DRY_RUN], array_keys($add->getInputDefinitions()));
    $values = $add->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertArrayHasKey('type', $values->getPropertyDefinitions());
    $this->assertArrayHasKey('DataSurfaceUniqueNodeType', $values->getPropertyDefinitions()['type']->getConstraints());
    $this->assertTrue($values->isRequired());
    $this->assertSame([], $add->getInputDefinitionRefiners());

    // Editing one needs the content type, by id; its machine name is
    // known, so it is no input, and nothing has to be sent again.
    $edit = $manager->getDefinition('data_surface:node.type:edit');
    $this->assertSame(['type', SituationInputs::VALUES, SituationInputs::DRY_RUN], array_keys($edit->getInputDefinitions()));
    $this->assertSame('string', $edit->getInputDefinition('type')->getDataType());
    $this->assertTrue($edit->getInputDefinition('type')->isRequired());
    $values = $edit->getInputDefinition(SituationInputs::VALUES);
    $this->assertArrayNotHasKey('type', $values->getPropertyDefinitions());
    $this->assertArrayHasKey('name', $values->getPropertyDefinitions());
    $this->assertFalse($values->isRequired());
    $this->assertSame([SituationInputs::VALUES => ['type']], $edit->getInputDefinitionRefiners());

    // A field: add takes the entity type and bundle, reuse a storage and
    // a bundle, edit the field, and each leaves out what it knows.
    $field_add = $manager->getDefinition('data_surface:field.instance:add');
    $this->assertSame(['entity_type_id', 'bundle', SituationInputs::VALUES, SituationInputs::DRY_RUN], array_keys($field_add->getInputDefinitions()));
    $this->assertSame(
      ['field_type', 'field_name', 'label', 'description', 'required', 'storage', 'settings'],
      array_keys($field_add->getInputDefinition(SituationInputs::VALUES)->getPropertyDefinitions()),
    );
    $reuse = $manager->getDefinition('data_surface:field.instance:reuse');
    $this->assertSame(['storage', 'bundle', SituationInputs::VALUES, SituationInputs::DRY_RUN], array_keys($reuse->getInputDefinitions()));
    $this->assertSame(
      ['label', 'description', 'required', 'storage', 'settings'],
      array_keys($reuse->getInputDefinition(SituationInputs::VALUES)->getPropertyDefinitions()),
    );
    $field_edit = $manager->getDefinition('data_surface:field.instance:edit');
    $this->assertSame(['field', SituationInputs::VALUES, SituationInputs::DRY_RUN], array_keys($field_edit->getInputDefinitions()));
  }

  /**
   * Tests that the values input is refined once the subject is known.
   *
   * Statically, an existing field's settings could be any field type's;
   * once the field is named, they are its type's.
   */
  public function testValuesAreRefinedToTheSubject(): void {
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ])->save();
    $tool = $this->tool('data_surface:field.instance:edit');
    $tool->setInputValue('field', 'entity_test.entity_test.field_address');
    $values = $tool->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $settings = $values->getPropertyDefinitions()['settings'];
    $this->assertInstanceOf(MapInputDefinition::class, $settings);
    $this->assertSame(['available_countries', 'langcode_override', 'field_overrides'], array_keys($settings->getPropertyDefinitions()));
  }

  /**
   * Tests adding a content type, then editing it, through the tools.
   */
  public function testNodeTypeToolsCreateThenUpdate(): void {
    $tool = $this->tool('data_surface:node.type:add');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Recipe', 'type' => 'recipe', 'title_label' => 'Dish']);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertTrue($result->getContextValues()[SituationInputs::COMMITTED]);
    $this->assertSame('Recipe', NodeType::load('recipe')?->label());

    $tool = $this->tool('data_surface:node.type:edit');
    $tool->setInputValue('type', 'recipe');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Recipes']);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $this->assertSame('Recipes', NodeType::load('recipe')->label());
    $this->assertSame('recipe', $result->getContextValues()[SituationInputs::VALUES]['type']);
    $this->assertSame('Dish', $result->getContextValues()[SituationInputs::VALUES]['title_label']);

    // A content type that is not there is the caller's to correct.
    $tool = $this->tool('data_surface:node.type:edit');
    $tool->setInputValue('type', 'ghost');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Ghost']);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('there is none with the id', (string) $tool->getResult()->getMessage());
  }

  /**
   * Tests a dry run, which is judged and writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    $tool = $this->tool('data_surface:node.type:add');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Rehearsal', 'type' => 'rehearsal']);
    $tool->setInputValue(SituationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertFalse($result->getContextValues()[SituationInputs::COMMITTED]);
    $this->assertNull(NodeType::load('rehearsal'));
    // What prepare rehearsed is the preview: the node type as config
    // storage would have been handed it.
    $prepared = $result->getContextValues()[SituationInputs::PREPARED];
    $this->assertSame('Rehearsal', $prepared[NodeTypeTarget::NODE_TYPE]['name']);
    $this->assertSame([], $prepared[NodeTypeTarget::OVERRIDES]);

    // A dry run is refused for what storage would refuse.
    $tool = $this->tool('data_surface:node.type:add');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => "Two\nlines", 'type' => 'two_lines']);
    $tool->setInputValue(SituationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('name: Labels are not allowed to span multiple lines', (string) $tool->getResult()->getMessage());
  }

  /**
   * Tests access: the situation's permission, then the access class.
   */
  public function testAccessIsTheSituations(): void {
    $this->setUpCurrentUser([], ['administer content types']);
    $tool = $this->tool('data_surface:node.type:add');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Refused', 'type' => 'refused']);
    $this->assertFalse($tool->access());
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertNull(NodeType::load('refused'));

    $this->setUpCurrentUser([], ['administer content types', 'administer data surface node type demo']);
    $tool = $this->tool('data_surface:node.type:add');
    $tool->setInputValue(SituationInputs::VALUES, ['name' => 'Allowed', 'type' => 'allowed']);
    $this->assertTrue($tool->access());
  }

  /**
   * Tests reusing a storage, and editing it with its storage child.
   *
   * The reuse tool knows the storage, so it adds the field without being
   * told its name or type; the edit tool changes the storage's
   * cardinality through the field, and refuses shrinking it once the
   * field holds data.
   */
  public function testFieldToolsReuseAndEditTheStorage(): void {
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    $tool = $this->tool('data_surface:field.instance:reuse');
    $tool->setInputValue('storage', 'entity_test.field_address');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue(SituationInputs::VALUES, ['label' => 'Address', 'storage' => ['cardinality' => 3]]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame('Address', FieldConfig::loadByName('entity_test', 'entity_test', 'field_address')?->getLabel());
    $this->assertSame(3, FieldStorageConfig::loadByName('entity_test', 'field_address')?->getCardinality());

    $this->container->get('entity_type.manager')->getStorage('entity_test')->create([
      'field_address' => [
        ['country_code' => 'US', 'locality' => 'Boston'],
        ['country_code' => 'CA', 'locality' => 'Toronto'],
      ],
    ])->save();
    $tool = $this->tool('data_surface:field.instance:edit');
    $tool->setInputValue('field', 'entity_test.entity_test.field_address');
    $tool->setInputValue(SituationInputs::VALUES, ['storage' => ['cardinality' => 2]]);
    $tool->execute();
    // Refused before the tool runs: the refined values input carries the
    // has-data constraint the storage's edit situation added.
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('(property storage) (property cardinality) This value should be 3 or more.', (string) $tool->getResult()->getMessage());
    $this->assertSame(3, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());
  }

}
