<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface\ResourceBuild\StorageAdapter;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_extras\Alter\NodeTypeAlter;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Hook\NodeTypeResourceHooks;
use Drupal\data_surface_demo_node_type\Resource\NodeTypeResource;
use Drupal\data_surface_demo_node_type\Storage\NodeTypeStorage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content type resource.
 *
 * NodeTypeResource with its two operations: add knows nothing, so the
 * machine name is open and must be unique; edit knows the machine name,
 * so it is locked. NodeTypeStorage writes the node type entity and the
 * base field overrides behind it, loading by the identity the context
 * knows; NodeTypeAccess answers what the operation's permission cannot.
 * The extras module's review settings arrive through NodeTypeAlter, and
 * are stored as seconds through the storage layout it hands the resource.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NodeTypeResourceTest extends DataSurfaceKernelTestBase {

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
    'block',
    'data_surface',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
  }

  /**
   * Builds the resource in a context, and its storage.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return array{0: \Drupal\data_surface\DataSurfaceInterface, 1: \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface}
   *   The built resource and its composed storage.
   */
  protected function served(OperationContext $context): array {
    $surface = $this->resources()->build(NodeTypeResource::class, $context);
    return [$surface, $this->resources()->storage(NodeTypeResource::class, $context, $surface)];
  }

  /**
   * Submits values to a built resource and its storage.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The built resource.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface $storage
   *   The storage.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(DataSurfaceInterface $surface, DataSurfaceStorageInterface $storage, array $values, bool $dry_run = FALSE) {
    return $this->pipeline()->submit($surface, $values, $storage, $dry_run);
  }

  /**
   * Reads the node field definitions for one bundle.
   *
   * @param string $bundle
   *   The content type machine name.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The field definitions, keyed by field name.
   */
  protected function nodeFields(string $bundle): array {
    $manager = $this->container->get('entity_field.manager');
    $manager->clearCachedFieldDefinitions();
    return $manager->getFieldDefinitions('node', $bundle);
  }

  /**
   * Loads the stored base field override for one field and bundle.
   *
   * @param string $field_name
   *   The base field name.
   * @param string $bundle
   *   The content type machine name.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The override, or NULL when none is stored.
   */
  protected function baseFieldOverride(string $field_name, string $bundle) {
    return $this->container->get('entity_type.manager')
      ->getStorage('base_field_override')
      ->load('node.' . $bundle . '.' . $field_name);
  }

  /**
   * Tests what discovery reads off the resource: identity, storage, access.
   */
  public function testTheResourceIsDiscovered(): void {
    $definition = $this->container->get('data_surface.resource_registry')->getDefinition('node.type');
    $this->assertSame(NodeTypeResource::class, $definition->class);
    $this->assertSame(['type'], $definition->identity);
    $this->assertSame(NodeTypeStorage::class, $definition->storage);
    $this->assertSame(NodeTypeAccess::class, $definition->access);
    $operations = $this->container->get('data_surface.resource_registry')->getOperations(NodeTypeResource::class);
    $this->assertSame(['add', 'edit'], array_keys($operations));
    $this->assertSame(NodeTypeResource::PERMISSION, $operations['edit']->permission);
    $this->assertSame([NodeTypeAlter::class], array_map(static fn ($alter): string => $alter->class, $definition->alters));
  }

  /**
   * Tests the add operation: an open machine name, unique on the site.
   */
  public function testAddLeavesTheMachineNameOpenAndUnique(): void {
    $surface = $this->resources()->buildOperation(NodeTypeResource::class, 'add');
    $this->assertFalse($surface->isLocked('type'));
    $this->assertArrayHasKey('DataSurfaceUniqueNodeType', $surface->getDefinition('type')->getConstraints());

    // The defaults a new content type starts from, as declared.
    $defaults = $surface->getDefaultValues();
    $this->assertTrue($defaults['status']);
    $this->assertFalse($defaults['promote']);
    $this->assertFalse($defaults['sticky']);
    $this->assertSame('Title', $defaults['title_label']);
    $this->assertSame(1, $defaults['preview_mode']);
    $this->assertTrue($defaults['new_revision']);
    $this->assertTrue($defaults['display_submitted']);

    // Declared in core's order, then the alter's mount.
    $this->assertSame(
      [
        'name',
        'type',
        'description',
        'title_label',
        'preview_mode',
        'help',
        'status',
        'promote',
        'sticky',
        'new_revision',
        'display_submitted',
        'third_party_settings',
      ],
      $surface->getDefinitions()->names(),
    );

    // A taken machine name is a violation, and a free one is not.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $values = ['name' => 'Article again', 'type' => 'article'] + $defaults;
    $errors = $this->pipeline()->validate($surface, $values);
    $this->assertContains('type', $errors->keys());
    $this->assertStringContainsString('already exists', (string) $errors->byKey('type')[0]->message);
    $this->assertCount(0, $this->pipeline()->validate($surface, ['type' => 'fresh_type'] + $values));
  }

  /**
   * Tests the edit operation: the machine name is locked to the type.
   */
  public function testEditLocksTheMachineName(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article', 'help' => 'Some help.'])->save();
    $context = $this->resources()->operation(NodeTypeResource::class, 'edit', ['type' => 'article']);
    $this->assertSame(['type' => 'article'], $context->known);
    $this->assertFalse($context->creates);
    [$surface, $storage] = $this->served($context);

    $this->assertTrue($surface->isLocked('type'));
    $this->assertSame('article', $surface->getDefault('type'));
    // No uniqueness check against yourself on edit.
    $this->assertArrayNotHasKey('DataSurfaceUniqueNodeType', $surface->getDefinition('type')->getConstraints());

    // Current values come from the storage, not from the built resource.
    $stored = $storage->load($surface);
    $this->assertSame('Article', $stored['name']);
    $this->assertSame('Some help.', $stored['help']);
    $this->assertSame('Title', $stored['title_label']);

    // The generated form renders the locked value, disabled, and a
    // tampered submit cannot move it.
    $form_builder = $this->container->get('data_surface.form_builder');
    $form = $form_builder->buildSurfaceForm($surface, array_replace($surface->getDefaultValues(), $stored), new FormState());
    $this->assertTrue($form['type']['#disabled']);
    $this->assertSame('article', $form['type']['#default_value']);
    $this->assertSame('select', $form['preview_mode']['#type']);
    $this->assertSame('textarea', $form['help']['#type']);
    $form_state = new FormState();
    $form_state->setValues(['name' => 'Renamed', 'type' => 'evil_rename']);
    $values = $form_builder->extractSurfaceValues($surface, $form, $form_state, $stored);
    $this->assertSame('article', $values['type']);
    $this->assertSame('Renamed', $values['name']);

    // An id that names nothing is refused by the operation, by name.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('there is none with the id "ghost"');
    $this->resources()->operation(NodeTypeResource::class, 'edit', ['type' => 'ghost']);
  }

  /**
   * Tests the storage creating a content type, then updating it.
   */
  public function testStorageCreatesThenUpdates(): void {
    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'add'));
    $this->assertSame([], $storage->load($surface));
    $result = $this->submit($surface, $storage, [
      'name' => 'Recipe',
      'type' => 'recipe',
      'title_label' => 'Recipe name',
      'description' => 'Cooking instructions.',
      'preview_mode' => '2',
      'display_submitted' => 0,
      'sticky' => 1,
    ]);
    $this->assertTrue($result->committed, ViolationSummary::fromViolations($result->violations));

    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeTypeInterface::class, $type);
    $this->assertSame('Recipe', $type->label());
    $this->assertSame('Cooking instructions.', $type->getDescription());
    $this->assertSame(2, $type->getPreviewMode(FALSE)->value);
    $this->assertFalse($type->displaySubmitted());
    $this->assertTrue($type->shouldCreateNewRevision());
    $fields = $this->nodeFields('recipe');
    $this->assertSame('Recipe name', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['sticky']->getDefaultValueLiteral()[0]['value']);
    // Compared before writing: what did not move wrote no override.
    $this->assertNull($this->baseFieldOverride('promote', 'recipe'));
    $this->assertNotNull($this->baseFieldOverride('sticky', 'recipe'));

    // Edited through the edit operation: a partial payload changes what
    // it names, the rest is loaded, and the machine name cannot move.
    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'edit', ['type' => $type]));
    $this->assertSame('Recipe name', $storage->load($surface)['title_label']);
    $edit = $this->submit($surface, $storage, [
      'name' => 'Recipes',
      'type' => 'tampered',
      'promote' => 1,
      'title_label' => 'Dish name',
    ]);
    $this->assertTrue($edit->committed, ViolationSummary::fromViolations($edit->violations));
    $this->assertSame('recipe', $edit->values['type']);
    $this->assertNull(NodeType::load('tampered'));
    $reloaded = NodeType::load('recipe');
    $this->assertSame('Recipes', $reloaded->label());
    $this->assertSame(2, $reloaded->getPreviewMode(FALSE)->value);
    $this->assertSame('Cooking instructions.', $reloaded->getDescription());
    $fields = $this->nodeFields('recipe');
    $this->assertSame('Dish name', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);
    $this->assertTrue((bool) $fields['sticky']->getDefaultValueLiteral()[0]['value']);
  }

  /**
   * Tests a dry run: the prepared storage layout, and nothing written.
   *
   * Prepare rehearses the write: the node type's exported array, as
   * config storage would be handed it, and one exported override per base
   * field that would move — the title label and promote here, and not
   * status, whose default did not move.
   */
  public function testDryRunReturnsThePreparedStorageLayoutAndWritesNothing(): void {
    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'add'));
    $result = $this->submit($surface, $storage, [
      'name' => 'Dry run',
      'type' => 'dry_run',
      'title_label' => 'Dish',
      'promote' => 1,
      'description' => '',
    ], TRUE);
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $this->assertFalse($result->committed);

    $this->assertInstanceOf(StorageAdapter::class, $storage);
    $prepared = $storage->preview($result->prepared);
    $this->assertSame([NodeTypeStorage::NODE_TYPE, NodeTypeStorage::OVERRIDES], array_keys($prepared));
    $node_type = $prepared[NodeTypeStorage::NODE_TYPE];
    $this->assertSame('Dry run', $node_type['name']);
    $this->assertSame('dry_run', $node_type['type']);
    // Stored as core's own form stores an empty description: as none.
    $this->assertNull($node_type['description']);
    $this->assertTrue($node_type['new_revision']);
    $overrides = $prepared[NodeTypeStorage::OVERRIDES];
    $this->assertSame(['title', 'promote'], array_keys($overrides));
    $this->assertSame('node.dry_run.title', $overrides['title']['id']);
    $this->assertSame('Dish', $overrides['title']['label']);
    $this->assertSame([['value' => TRUE]], $overrides['promote']['default_value']);

    $this->assertNull(NodeType::load('dry_run'));
    $this->assertNull($this->baseFieldOverride('title', 'dry_run'));
    $this->assertNull($this->baseFieldOverride('promote', 'dry_run'));

    // And what is rehearsed is what a real submit stores.
    $result = $this->submit($surface, $storage, [
      'name' => 'Dry run',
      'type' => 'dry_run',
      'title_label' => 'Dish',
      'promote' => 1,
    ]);
    $this->assertTrue($result->committed, ViolationSummary::fromViolations($result->violations));
    $stored = $this->container->get('config.factory')->get('node.type.dry_run')->getRawData();
    $this->assertSame(
      array_diff_key($result->prepared->artifact[NodeTypeStorage::NODE_TYPE], ['dependencies' => TRUE]),
      array_diff_key($stored, ['dependencies' => TRUE]),
    );
    $this->assertSame('Dish', (string) $this->nodeFields('dry_run')['title']->getLabel());
  }

  /**
   * Tests a name core's schema refuses is refused by the resource first.
   *
   * The node type's schema is fully validatable, and a required label may
   * not hold a line break. The resource states the same pattern, since the
   * build refuses a resource wider than the schema its storage writes, so
   * the refusal is the resource's, in its own words, at every door, for a
   * dry run as for a real submit, before anything is written. Prepare still
   * holds the rehearsed entity to the schema, which says the same in core's
   * words to a caller that reaches the storage alone.
   */
  public function testSchemaRefusesAnInvalidNodeTypeAtPrepare(): void {
    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'add'));
    $values = ['name' => "Two\nlines", 'type' => 'two_lines'];
    $this->assertSame(['name'], $this->pipeline()->validate($surface, $this->pipeline()->accept($surface, $values, []))->keys());

    foreach ([TRUE, FALSE] as $dry_run) {
      $result = $this->submit($surface, $storage, $values, $dry_run);
      $this->assertFalse($result->isValid());
      $this->assertFalse($result->committed);
      $this->assertNull($result->prepared);
      $this->assertSame(['name'], $result->violations->keys());
      $this->assertSame('The name may not span lines or hold control characters.', (string) $result->violations->byKey('name')[0]->message);
    }
    $this->assertNull(NodeType::load('two_lines'));

    // The storage alone says the same, with nothing else in between.
    $this->expectException(StorageViolationsException::class);
    $this->expectExceptionMessage('Labels are not allowed to span multiple lines or contain control characters.');
    $this->container->get('class_resolver')->getInstanceFromDefinition(NodeTypeStorage::class)
      ->prepare($this->resources()->operation(NodeTypeResource::class, 'add'), [
        'name' => "Two\nlines",
        'type' => 'two_lines',
      ]);
  }

  /**
   * Tests the alter: the review settings, stored as seconds.
   *
   * Mounted under the extras module's name, asked for as an amount and a
   * unit, and stored as the seconds the extras module's schema says,
   * through the storage layout the alter hands the resource; read back,
   * one week is one week again. The storage writes and reads the seconds
   * without knowing the extras module exists.
   */
  public function testAlterStoresTheReviewSettingsInTheirOwnLayout(): void {
    $extras = 'data_surface_demo_extras';
    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'add'));
    $mount = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mount);
    $module = $mount->getPropertyDefinition($extras);
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $module);
    $this->assertSame('Review deadline', (string) $module->getPropertyDefinition(NodeTypeReviewSettings::DEADLINE)?->getLabel());
    $this->assertNotNull($surface->getThirdPartyLayout($extras));

    $week = [NodeTypeReviewSettings::AMOUNT => 1, NodeTypeReviewSettings::UNIT => 'weeks'];
    $result = $this->submit($surface, $storage, [
      'name' => 'Reviewed',
      'type' => 'reviewed',
      'third_party_settings' => [
        $extras => [
          NodeTypeReviewSettings::DEADLINE => $week,
          NodeTypeReviewSettings::TAGS => ['news'],
        ],
      ],
    ]);
    $this->assertTrue($result->committed, ViolationSummary::fromViolations($result->violations));
    $type = NodeType::load('reviewed');
    $this->assertSame(604800, $type->getThirdPartySetting($extras, NodeTypeReviewSettings::DEADLINE));
    $this->assertSame(['news'], $type->getThirdPartySetting($extras, NodeTypeReviewSettings::TAGS));
    $this->assertContains($extras, $type->getDependencies()['module'] ?? []);

    [$surface, $storage] = $this->served($this->resources()->operation(NodeTypeResource::class, 'edit', ['type' => $type]));
    $this->assertSame($week, $storage->load($surface)['third_party_settings'][$extras][NodeTypeReviewSettings::DEADLINE]);

    // Past thirty days is refused on the amount, in the caller's units.
    $late_deadline = [NodeTypeReviewSettings::AMOUNT => 45, NodeTypeReviewSettings::UNIT => 'days'];
    $late = $this->submit($surface, $storage, [
      'third_party_settings' => [$extras => [NodeTypeReviewSettings::DEADLINE => $late_deadline]],
    ]);
    $this->assertSame(
      ['third_party_settings.' . $extras . '.' . NodeTypeReviewSettings::DEADLINE . '.' . NodeTypeReviewSettings::AMOUNT],
      array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($late->violations, FALSE)),
    );
  }

  /**
   * Tests access: the operation's permission, then the entity's answer.
   *
   * Both have to allow, and neither alone opens anything; the routes the
   * module ships give the same answer, because they ask the same thing.
   */
  public function testAccessIsThePermissionThenTheEntity(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $access_manager = $this->container->get('access_manager');
    // User 1 bypasses every access check, so it is created and set aside
    // before any account below is made.
    $this->setUpCurrentUser();
    $matrix = [
      'nobody' => [[], FALSE],
      'content types only' => [['administer content types'], FALSE],
      'demo permission only' => [[NodeTypeResourceHooks::PERMISSION], FALSE],
      'both' => [['administer content types', NodeTypeResourceHooks::PERMISSION], TRUE],
    ];
    $add = $this->resources()->operation(NodeTypeResource::class, 'add');
    $edit = $this->resources()->operation(NodeTypeResource::class, 'edit', ['type' => 'article']);
    foreach ($matrix as $who => [$permissions, $allowed]) {
      $account = $this->createUser($permissions);
      $add_access = $this->resources()->access(NodeTypeResource::class, $add, $account);
      $edit_access = $this->resources()->access(NodeTypeResource::class, $edit, $account);
      $this->assertSame($allowed, $add_access->isAllowed(), $who . ' on add.');
      $this->assertSame($allowed, $edit_access->isAllowed(), $who . ' on edit.');
      $this->assertSame($allowed, $access_manager->checkNamedRoute('data_surface_demo_node_type.add', [], $account), $who . ' on the add route.');
      $this->assertSame($allowed, $access_manager->checkNamedRoute('data_surface_demo_node_type.edit', ['type' => 'article'], $account), $who . ' on the edit route.');
      $this->assertContains('user.permissions', CacheableMetadata::createFromObject($edit_access)->getCacheContexts());
    }

    // An edit context naming a content type that is not there is refused
    // by the access class, until it is created.
    $account = $this->createUser(['administer content types', NodeTypeResourceHooks::PERMISSION]);
    $missing = $this->resources()->access(NodeTypeResource::class, new OperationContext('edit', known: ['type' => 'ghost']), $account);
    $this->assertTrue($missing->isForbidden());
    $this->assertContains('config:node_type_list', CacheableMetadata::createFromObject($missing)->getCacheTags());
  }

  /**
   * Tests the operation link, which asks the edit operation's access.
   */
  public function testOperationLinkAsksTheEditOperation(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node_type = NodeType::load('article');
    $module_handler = $this->container->get('module_handler');

    $this->setUpCurrentUser([], ['administer content types']);
    $arguments = [$node_type, new CacheableMetadata()];
    $this->assertSame([], $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', $arguments));

    $this->setUpCurrentUser([], ['administer content types', NodeTypeResourceHooks::PERMISSION]);
    $cacheability = new CacheableMetadata();
    $operations = $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', [$node_type, $cacheability]);
    $this->assertSame('Edit (resource)', (string) $operations['surface_edit']['title']);
    $this->assertSame('/admin/structure/types/manage/article/surface-edit', $operations['surface_edit']['url']->toString());
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
  }

}
