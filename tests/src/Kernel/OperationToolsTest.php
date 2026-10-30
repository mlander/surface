<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface_resource_test\Resource\PinnedNoteResource;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tools generated from operations.
 *
 * One tool per operation of every discovered resource that names
 * storage, `data_surface:<resource id>:<operation id>`, its inputs the
 * operation's parameters, the resource's keys the operation does not
 * know as `values`, and `dry_run`. No tool class names a resource.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OperationToolsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The keys the node type resource requires whose defaults hold a value.
   *
   * Required is the resource's word, so a payload names them too: the Tool
   * API refuses a required property left out, default or not.
   */
  protected const NODE_TYPE_REQUIRED = [
    'title_label' => 'Title',
    'preview_mode' => NodePreviewMode::Optional->value,
  ];

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
   * Tests that every resource with storage has one tool per operation.
   */
  public function testResourcesWithStorageHaveOneToolPerOperation(): void {
    $ids = array_keys(array_filter(
      $this->container->get('plugin.manager.tool')->getDefinitions(),
      static fn (string $id): bool => str_starts_with($id, 'data_surface:') && substr_count($id, ':') === 2,
      ARRAY_FILTER_USE_KEY,
    ));
    sort($ids);
    $this->assertSame([
      'data_surface:field.instance:create',
      'data_surface:field.instance:reuse',
      'data_surface:field.instance:update',
      'data_surface:field.storage:update',
      'data_surface:node.type:create',
      'data_surface:node.type:update',
    ], $ids);
    // The address settings name storage and have no operation of their
    // own: they are only ever a field's.
    $this->assertArrayNotHasKey('data_surface:field.settings.address:create', $this->container->get('plugin.manager.tool')->getDefinitions());
  }

  /**
   * Tests the first derivation rule: a permission nothing can name.
   *
   * The field storage's create operation needs nothing, and its permission
   * names %entity_type, which no parameter supplies: nothing a caller
   * sends could name the permission, so it could never be allowed, and
   * it is no tool. Its edit takes the entity type and the field name, so
   * it is one.
   */
  public function testUnnameablePermissionIsNoTool(): void {
    $registry = $this->container->get('data_surface.resource_registry');
    $this->assertSame(['entity_type'], $registry->getOperation('field.storage', 'create')->unresolvablePlaceholders());
    $this->assertSame([], $registry->getOperation('field.storage', 'update')->unresolvablePlaceholders());
    $this->assertSame([], $registry->getOperation('field.instance', 'create')->unresolvablePlaceholders());
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $this->assertArrayNotHasKey('data_surface:field.storage:create', $definitions);
    $this->assertArrayHasKey('data_surface:field.storage:update', $definitions);
  }

  /**
   * Tests the second derivation rule: a plugin's resource is no tool.
   *
   * The pinned note resource names storage and an operation of its own,
   * and a block names it with #[UsesStructure], so it is that block's
   * configuration and is configured through the block host. A resource
   * with storage that no plugin uses, beside it, is a tool.
   */
  public function testPluginResourceIsNoTool(): void {
    $this->enableModules(['data_surface_resource_test']);
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $this->assertSame(['block:data_surface_resource_test_pinned_note'], $this->container->get('data_surface.resource_plugins')->usedBy(PinnedNoteResource::class));
    $this->assertArrayNotHasKey('data_surface:test.pinned_note:pin', $definitions);
    $this->assertArrayHasKey('data_surface:resource_test.pantry:create', $definitions);
  }

  /**
   * Tests what the tools advertise: parameters, values, dry run.
   */
  public function testInputsAreTheParametersThenTheOpenKeys(): void {
    $manager = $this->container->get('plugin.manager.tool');

    // Adding a content type needs nothing, so the values input is the
    // whole resource, machine name included and unique.
    $add = $manager->getDefinition('data_surface:node.type:create');
    $this->assertSame('Add a content type', (string) $add->getLabel());
    $this->assertSame([OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($add->getInputDefinitions()));
    $values = $add->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertArrayHasKey('type', $values->getPropertyDefinitions());
    $this->assertArrayHasKey('DataSurfaceUnique', $values->getPropertyDefinitions()['type']->getConstraints());
    $this->assertTrue($values->isRequired());
    $this->assertSame([], $add->getInputDefinitionRefiners());

    // Editing one needs the content type, by id; its machine name is
    // known, so it is no input. Editing changes what exists, so no key is
    // required: what the resource requires is said in words, and a key
    // left out keeps what is stored.
    $edit = $manager->getDefinition('data_surface:node.type:update');
    $this->assertSame(['type', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($edit->getInputDefinitions()));
    $this->assertSame('string', $edit->getInputDefinition('type')->getDataType());
    $this->assertTrue($edit->getInputDefinition('type')->isRequired());
    $values = $edit->getInputDefinition(OperationInputs::VALUES);
    $this->assertArrayNotHasKey('type', $values->getPropertyDefinitions());
    $this->assertArrayHasKey('name', $values->getPropertyDefinitions());
    $this->assertFalse($values->getPropertyDefinitions()['name']->isRequired());
    $this->assertFalse($values->getPropertyDefinitions()['title_label']->isRequired());
    $this->assertStringEndsWith('Required; a value already stored stands when left out.', (string) $values->getPropertyDefinitions()['name']->getDescription());
    $this->assertFalse($values->getPropertyDefinitions()['description']->isRequired());
    $this->assertStringNotContainsString('Required;', (string) $values->getPropertyDefinitions()['description']->getDescription());
    // The map is always sent, and requires nothing inside on an update.
    $this->assertTrue($values->isRequired());
    // Adding one creates, so what the resource requires is required.
    $this->assertTrue($add->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['name']->isRequired());
    $this->assertStringEndsWith(' Create: a key left out takes its default, and every key marked required must be sent.', (string) $add->getDescription());
    $this->assertStringEndsWith(' Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.', (string) $edit->getDescription());
    // Editing changes what exists, so the listing carries no default,
    // declared or stored: a key left out keeps its current value.
    $this->assertNull($values->getPropertyDefinitions()['title_label']->getDefaultValue());
    $this->assertStringStartsWith('Changes a content type, named by its machine name, which stays as it is.', (string) $edit->getDescription());
    // Adding one creates, so it lists the declared defaults.
    $this->assertSame('Title', $add->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['title_label']->getDefaultValue());
    $this->assertStringNotContainsString('keeps its value', (string) $add->getDescription());
    $this->assertSame([OperationInputs::VALUES => ['type']], $edit->getInputDefinitionRefiners());

    // A field: add takes the entity type and bundle, reuse and edit the
    // entity type, bundle and field name, and each leaves out what it
    // knows.
    $field_add = $manager->getDefinition('data_surface:field.instance:create');
    $this->assertSame(['entity_type', 'bundle', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($field_add->getInputDefinitions()));
    $this->assertSame(
      ['field_type', 'field_name', 'label', 'description', 'required', 'storage', 'settings'],
      array_keys($field_add->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()),
    );
    $reuse = $manager->getDefinition('data_surface:field.instance:reuse');
    $this->assertSame(['entity_type', 'bundle', 'field_name', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($reuse->getInputDefinitions()));
    $this->assertSame(
      ['label', 'description', 'required', 'storage', 'settings'],
      array_keys($reuse->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()),
    );
    $field_edit = $manager->getDefinition('data_surface:field.instance:update');
    $this->assertSame(['entity_type', 'bundle', 'field_name', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($field_edit->getInputDefinitions()));
    $this->assertSame(
      ['label', 'description', 'required', 'storage', 'settings'],
      array_keys($field_edit->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()),
      'An edit names the whole field: the field type it leaves out follows from it.',
    );
    // Each part says what it is, as the key of the same name does.
    $this->assertSame('The machine name of the entity type the field is on.', (string) $field_edit->getInputDefinition('entity_type')->getDescription());
    $this->assertSame('The bundle the field is on, for example article or page.', (string) $field_edit->getInputDefinition('bundle')->getDescription());
    $this->assertSame('The machine name of the field storage, for example field_tags.', (string) $field_edit->getInputDefinition('field_name')->getDescription());
    $this->assertSame(['entity_type', 'field_name', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($manager->getDefinition('data_surface:field.storage:update')->getInputDefinitions()));
  }

  /**
   * Tests that the values input is refined once the subject is known.
   *
   * Statically, an existing field's settings could be any field type's;
   * once the field is named, they are its type's. The field types are a
   * list the site decides: this site offers more than the shipped limit,
   * past which the settings are deferred, so the limit is raised here.
   */
  public function testValuesAreRefinedToTheSubject(): void {
    $this->installConfig(['data_surface']);
    $this->config('data_surface.settings')->set('site_list_limit', 100)->save();
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ])->save();
    $tool = $this->tool('data_surface:field.instance:update');
    $tool->setInputValue('entity_type', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $settings = $values->getPropertyDefinitions()['settings'];
    $this->assertInstanceOf(MapInputDefinition::class, $settings);
    $this->assertSame(['available_countries', 'langcode_override', 'field_overrides'], array_keys($settings->getPropertyDefinitions()));
  }

  /**
   * Tests adding a content type, then editing it, through the tools.
   */
  public function testNodeTypeToolsCreateThenUpdate(): void {
    $tool = $this->tool('data_surface:node.type:create');
    $tool->setInputValue(OperationInputs::VALUES, [
      'name' => 'Recipe',
      'type' => 'recipe',
      'description' => 'Food',
      'title_label' => 'Dish',
    ] + self::NODE_TYPE_REQUIRED);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertTrue($result->getContextValues()[OperationInputs::COMMITTED]);
    $this->assertSame('Recipe', NodeType::load('recipe')?->label());

    // Once the content type is named, the values input is refined to it:
    // each key defaulting to what the content type stores now, and, as the
    // listing and the door say, none required: what the resource requires
    // is said in words.
    $tool = $this->tool('data_surface:node.type:update');
    $tool->setInputValue('type', 'recipe');
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame('Recipe', $properties['name']->getDefaultValue());
    $this->assertSame('Dish', $properties['title_label']->getDefaultValue());
    $this->assertSame('Food', $properties['description']->getDefaultValue());
    $this->assertTrue($values->isRequired());
    $this->assertSame([], array_keys(array_filter($properties, static fn ($property): bool => $property->isRequired())));
    $this->assertStringEndsWith('Required; a value already stored stands when left out.', (string) $properties['name']->getDescription());
    $this->assertStringEndsWith('Required; a value already stored stands when left out.', (string) $properties['title_label']->getDescription());

    // The name left out keeps what is stored.
    $tool->setInputValue(OperationInputs::VALUES, ['description' => 'Dishes']);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $recipe = NodeType::load('recipe');
    $this->assertSame(['Recipe', 'Dishes'], [$recipe->label(), $recipe->getDescription()]);

    // The name sent empty is refused in its own words, and nothing is
    // written.
    $tool = $this->tool('data_surface:node.type:update');
    $tool->setInputValue('type', 'recipe');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => '', 'description' => 'Plates']);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n" . '/name: Name is required.', PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage()));
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $this->assertSame('Dishes', NodeType::load('recipe')->getDescription());

    // A required key left out keeps what is stored, as an optional one
    // does: the call reaches the pipeline, which judges the merged
    // document.
    $tool = $this->tool('data_surface:node.type:update');
    $tool->setInputValue('type', 'recipe');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => 'Recipes', 'description' => 'Food']);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $this->assertSame('Recipes', NodeType::load('recipe')->label());
    $this->assertSame('recipe', $result->getContextValues()[OperationInputs::VALUES]['type']);
    $this->assertSame('Dish', $result->getContextValues()[OperationInputs::VALUES]['title_label']);
    $this->assertSame('Food', NodeType::load('recipe')->getDescription());

    // A content type that is not there is the caller's to correct.
    $tool = $this->tool('data_surface:node.type:update');
    $tool->setInputValue('type', 'ghost');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => 'Ghost'] + self::NODE_TYPE_REQUIRED);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('there is none with the id', (string) $tool->getResult()->getMessage());
  }

  /**
   * Tests a dry run, which is judged and writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    $tool = $this->tool('data_surface:node.type:create');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => 'Rehearsal', 'type' => 'rehearsal'] + self::NODE_TYPE_REQUIRED);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertFalse($result->getContextValues()[OperationInputs::COMMITTED]);
    $this->assertNull(NodeType::load('rehearsal'));
    // The answer is the accepted document; what storage rehearsed stays
    // with the PHP result.
    $this->assertSame('Rehearsal', $result->getContextValues()[OperationInputs::VALUES]['name']);
    $this->assertArrayNotHasKey('prepared', $result->getContextValues());

    // A dry run is refused for what storage would refuse, which the
    // resource states as core's schema does.
    $tool = $this->tool('data_surface:node.type:create');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => "Two\nlines", 'type' => 'two_lines'] + self::NODE_TYPE_REQUIRED);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('/name: The name may not span lines or hold control characters.', (string) $tool->getResult()->getMessage());
  }

  /**
   * Tests access: the operation's permission, then the access class.
   */
  public function testAccessIsTheOperations(): void {
    $this->setUpCurrentUser([], ['administer content types']);
    $tool = $this->tool('data_surface:node.type:create');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => 'Refused', 'type' => 'refused'] + self::NODE_TYPE_REQUIRED);
    $this->assertFalse($tool->access());
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertNull(NodeType::load('refused'));

    $this->setUpCurrentUser([], ['administer content types', 'administer data surface node type demo']);
    $tool = $this->tool('data_surface:node.type:create');
    $tool->setInputValue(OperationInputs::VALUES, ['name' => 'Allowed', 'type' => 'allowed'] + self::NODE_TYPE_REQUIRED);
    $this->assertTrue($tool->access());
  }

  /**
   * Tests reusing a storage, and editing it with its storage child.
   *
   * The reuse tool knows the storage, so it adds the field without being
   * told its name or type; the update tool changes the storage's
   * cardinality through the field, and refuses shrinking it once the
   * field holds data.
   */
  public function testFieldToolsReuseAndEditTheStorage(): void {
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    $tool = $this->tool('data_surface:field.instance:reuse');
    $tool->setInputValue('entity_type', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    $tool->setInputValue(OperationInputs::VALUES, ['label' => 'Address', 'storage' => ['cardinality' => 3]]);
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
    $tool = $this->tool('data_surface:field.instance:update');
    $tool->setInputValue('entity_type', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    $tool->setInputValue(OperationInputs::VALUES, ['label' => 'Address', 'storage' => ['cardinality' => 2]]);
    $tool->execute();
    // Refused when the tool runs: the pipeline holds the values to the
    // has-data constraint the storage's update operation added.
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('/storage/cardinality: This value should be 3 or more.', (string) $tool->getResult()->getMessage());
    $this->assertSame(3, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());
  }

}
