<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_extras\SurfaceAlter\NodeTypeAlter;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Hook\NodeTypeSurfaceHooks;
use Drupal\data_surface_demo_node_type\Surface\NodeTypeSurface;
use Drupal\data_surface_demo_node_type\Target\NodeTypeTarget;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content type surface, in the new spelling.
 *
 * NodeTypeSurface with its two situations: add knows nothing, so the
 * machine name is open and must be unique; edit knows the machine name,
 * so it is locked. NodeTypeTarget writes the node type entity and the
 * base field overrides behind it, loading by the identity the context
 * knows; NodeTypeAccess answers what the situation's permission cannot.
 * The extras module's review settings arrive through NodeTypeAlter, and
 * are stored as seconds through the storage shape it hands the surface.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NodeTypeSurfaceTest extends DataSurfaceKernelTestBase {

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
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The service.
   */
  protected function surfaces(): SurfacesInterface {
    return $this->container->get('data_surface.surfaces');
  }

  /**
   * Builds the surface in a context, and its target.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return array{0: \Drupal\data_surface\DataSurfaceInterface, 1: \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface}
   *   The surface and its composed target.
   */
  protected function served(SurfaceContext $context): array {
    $surface = $this->surfaces()->build(NodeTypeSurface::class, $context);
    return [$surface, $this->surfaces()->target(NodeTypeSurface::class, $context, $surface)];
  }

  /**
   * Submits values to a surface and its target.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(DataSurfaceInterface $surface, DataSurfaceTargetInterface $target, array $values, bool $dry_run = FALSE) {
    return $this->pipeline()->submit($surface, $values, $target, $dry_run);
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
   * Tests what discovery reads off the surface: identity, target, access.
   */
  public function testTheSurfaceIsDiscovered(): void {
    $definition = $this->container->get('data_surface.surface_registry')->getDefinition('node.type');
    $this->assertSame(NodeTypeSurface::class, $definition->class);
    $this->assertSame(['type'], $definition->identity);
    $this->assertSame(NodeTypeTarget::class, $definition->target);
    $this->assertSame(NodeTypeAccess::class, $definition->access);
    $situations = $this->container->get('data_surface.surface_registry')->getSituations(NodeTypeSurface::class);
    $this->assertSame(['add', 'edit'], array_keys($situations));
    $this->assertSame(NodeTypeSurface::PERMISSION, $situations['edit']->permission);
    $this->assertSame([NodeTypeAlter::class], array_map(static fn ($alter): string => $alter->class, $definition->alters));
  }

  /**
   * Tests the add situation: an open machine name, unique on the site.
   */
  public function testAddLeavesTheMachineNameOpenAndUnique(): void {
    $surface = $this->surfaces()->buildSituation(NodeTypeSurface::class, 'add');
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
   * Tests the edit situation: the machine name is locked to the type.
   */
  public function testEditLocksTheMachineName(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article', 'help' => 'Some help.'])->save();
    $context = $this->surfaces()->situation(NodeTypeSurface::class, 'edit', ['type' => 'article']);
    $this->assertSame(['type' => 'article'], $context->known);
    $this->assertFalse($context->creates);
    [$surface, $target] = $this->served($context);

    $this->assertTrue($surface->isLocked('type'));
    $this->assertSame('article', $surface->getDefault('type'));
    // No uniqueness check against yourself on edit.
    $this->assertArrayNotHasKey('DataSurfaceUniqueNodeType', $surface->getDefinition('type')->getConstraints());

    // Current values come from the target, not from the surface.
    $stored = $target->load($surface);
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

    // An id that names nothing is refused by the situation, by name.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('there is none with the id "ghost"');
    $this->surfaces()->situation(NodeTypeSurface::class, 'edit', ['type' => 'ghost']);
  }

  /**
   * Tests the target creating a content type, then updating it.
   */
  public function testTargetCreatesThenUpdates(): void {
    [$surface, $target] = $this->served(NodeTypeSurface::add());
    $this->assertSame([], $target->load($surface));
    $result = $this->submit($surface, $target, [
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

    // Edited through the edit situation: a partial payload changes what
    // it names, the rest is loaded, and the machine name cannot move.
    [$surface, $target] = $this->served(NodeTypeSurface::edit($type));
    $this->assertSame('Recipe name', $target->load($surface)['title_label']);
    $edit = $this->submit($surface, $target, [
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
   * Tests a dry run, which writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    [$surface, $target] = $this->served(NodeTypeSurface::add());
    $result = $this->submit($surface, $target, ['name' => 'Dry run', 'type' => 'dry_run', 'promote' => 1], TRUE);
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $this->assertFalse($result->committed);
    $this->assertNull(NodeType::load('dry_run'));
    $this->assertNull($this->baseFieldOverride('promote', 'dry_run'));
  }

  /**
   * Tests the alter: the review settings, stored as seconds.
   *
   * Mounted under the extras module's name, asked for as an amount and a
   * unit, and stored as the seconds the extras module's schema says,
   * through the storage shape the alter hands the surface; read back,
   * one week is one week again. The target writes and reads the seconds
   * without knowing the extras module exists.
   */
  public function testAlterStoresTheReviewSettingsInTheirOwnShape(): void {
    $extras = 'data_surface_demo_extras';
    [$surface, $target] = $this->served(NodeTypeSurface::add());
    $mount = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mount);
    $module = $mount->getPropertyDefinition($extras);
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $module);
    $this->assertSame('Review deadline', (string) $module->getPropertyDefinition(NodeTypeReviewSettings::DEADLINE)?->getLabel());
    $this->assertNotNull($surface->getThirdPartyShape($extras));

    $week = [NodeTypeReviewSettings::AMOUNT => 1, NodeTypeReviewSettings::UNIT => 'weeks'];
    $result = $this->submit($surface, $target, [
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

    [$surface, $target] = $this->served(NodeTypeSurface::edit($type));
    $this->assertSame($week, $target->load($surface)['third_party_settings'][$extras][NodeTypeReviewSettings::DEADLINE]);

    // Past thirty days is refused on the amount, in the caller's units.
    $late_deadline = [NodeTypeReviewSettings::AMOUNT => 45, NodeTypeReviewSettings::UNIT => 'days'];
    $late = $this->submit($surface, $target, [
      'third_party_settings' => [$extras => [NodeTypeReviewSettings::DEADLINE => $late_deadline]],
    ]);
    $this->assertSame(
      ['third_party_settings.' . $extras . '.' . NodeTypeReviewSettings::DEADLINE . '.' . NodeTypeReviewSettings::AMOUNT],
      array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($late->violations, FALSE)),
    );
  }

  /**
   * Tests access: the situation's permission, then the entity's answer.
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
      'demo permission only' => [[NodeTypeSurfaceHooks::PERMISSION], FALSE],
      'both' => [['administer content types', NodeTypeSurfaceHooks::PERMISSION], TRUE],
    ];
    $add = NodeTypeSurface::add();
    $edit = NodeTypeSurface::edit(NodeType::load('article'));
    foreach ($matrix as $who => [$permissions, $allowed]) {
      $account = $this->createUser($permissions);
      $add_access = $this->surfaces()->access(NodeTypeSurface::class, $add, $account);
      $edit_access = $this->surfaces()->access(NodeTypeSurface::class, $edit, $account);
      $this->assertSame($allowed, $add_access->isAllowed(), $who . ' on add.');
      $this->assertSame($allowed, $edit_access->isAllowed(), $who . ' on edit.');
      $this->assertSame($allowed, $access_manager->checkNamedRoute('data_surface_demo_node_type.add', [], $account), $who . ' on the add route.');
      $this->assertSame($allowed, $access_manager->checkNamedRoute('data_surface_demo_node_type.edit', ['type' => 'article'], $account), $who . ' on the edit route.');
      $this->assertContains('user.permissions', CacheableMetadata::createFromObject($edit_access)->getCacheContexts());
    }

    // An edit context naming a content type that is not there is refused
    // by the access class, until it is created.
    $account = $this->createUser(['administer content types', NodeTypeSurfaceHooks::PERMISSION]);
    $missing = $this->surfaces()->access(NodeTypeSurface::class, new SurfaceContext('edit', known: ['type' => 'ghost']), $account);
    $this->assertTrue($missing->isForbidden());
    $this->assertContains('config:node_type_list', CacheableMetadata::createFromObject($missing)->getCacheTags());
  }

  /**
   * Tests the operation link, which asks the edit situation's access.
   */
  public function testOperationLinkAsksTheEditSituation(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node_type = NodeType::load('article');
    $module_handler = $this->container->get('module_handler');

    $this->setUpCurrentUser([], ['administer content types']);
    $arguments = [$node_type, new CacheableMetadata()];
    $this->assertSame([], $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', $arguments));

    $this->setUpCurrentUser([], ['administer content types', NodeTypeSurfaceHooks::PERMISSION]);
    $cacheability = new CacheableMetadata();
    $operations = $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', [$node_type, $cacheability]);
    $this->assertSame('Edit (surface)', (string) $operations['surface_edit']['title']);
    $this->assertSame('/admin/structure/types/manage/article/surface-edit', $operations['surface_edit']['url']->toString());
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
  }

}
