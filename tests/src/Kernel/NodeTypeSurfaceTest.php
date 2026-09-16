<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormState;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\data_surface_demo_node_type\Hook\NodeTypeSurfaceHooks;
use Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared add and edit content type surface.
 *
 * One surface declaration serves both operations. Add carries a service
 * backed uniqueness constraint on the machine name, edit locks the
 * machine name, and the storage translation the demo used to do by hand
 * is a composite target: the node type config entity, then the base
 * field overrides holding the title label and the workflow defaults.
 * Nothing in the demo writes anything itself, so the same values reach
 * the same three destinations whether a form or a payload sent them.
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
    'data_surface',
    'data_surface_demo_node_type',
  ];

  /**
   * The surface provider under test.
   *
   * @var \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider
   */
  protected $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
    $this->provider = $this->container->get('data_surface_demo_node_type.provider');
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
    return $this->container->get('entity_field.manager')->getFieldDefinitions('node', $bundle);
  }

  /**
   * Tests the add surface: open machine name, unique on this site.
   */
  public function testAddSurface(): void {
    $surface = $this->provider->surfaceFor();

    $type = $surface->getDefinition('type');
    $this->assertFalse($surface->isLocked('type'));
    $this->assertArrayHasKey('DataSurfaceUniqueNodeType', $type->getConstraints());

    // Base field workflow defaults surface as the declared defaults.
    $defaults = $surface->getDefaultValues();
    $this->assertTrue($defaults['status']);
    $this->assertFalse($defaults['promote']);
    $this->assertFalse($defaults['sticky']);
    $this->assertSame('Title', $defaults['title_label']);
    $this->assertSame(1, $defaults['preview_mode']);

    // The three preview modes carry their meaning on the constraint, so
    // the option list a form renders and the list that validates are one
    // declaration.
    $constraint = $surface->getDefinition('preview_mode')->getConstraints()['LabeledChoice'];
    $this->assertSame([0, 1, 2], $constraint['choices']);
    $this->assertSame('Optional', (string) $constraint['labels'][1]);
    $options = $this->container->get('data_surface.options')
      ->resolve($surface->getDefinition('preview_mode'));
    $this->assertSame([0, 1, 2], array_keys($options->options));

    // A taken machine name is a violation, and a free one is not: the
    // same check a form, a recipe, or an agent would hit, with no form
    // involved.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $values = ['name' => 'Article again', 'type' => 'article'] + $defaults;
    $errors = $this->pipeline()->validate($surface, $values);
    $this->assertContains('type', $errors->keys());
    $this->assertStringContainsString('already exists', (string) $errors->byKey('type')[0]->message);
    $this->assertCount(0, $this->pipeline()->validate($surface, ['type' => 'fresh_type'] + $values));
  }

  /**
   * Tests that the provider interface's operations name the two surfaces.
   */
  public function testProviderInterfaceMapsTheOperation(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    // 'add' is the surface for a content type that does not exist yet.
    $this->assertFalse($this->provider->getDataSurface('add')->isLocked('type'));
    // 'edit:<type>' is the surface for one that does, so the identifier
    // is context rather than an editable value.
    $edit = $this->provider->getDataSurface('edit:article');
    $this->assertTrue($edit->isLocked('type'));
    $this->assertSame('Article', $edit->getDefault('name'));

    // A content type that is not there is a refusal, not an add form.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('no "ghost" content type');
    $this->provider->getDataSurface('edit:ghost');
  }

  /**
   * Tests that an operation this provider does not have is refused.
   */
  public function testProviderInterfaceRefusesAnUnknownOperation(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not for "configure"');
    $this->provider->getDataSurface('configure');
  }

  /**
   * Tests the edit surface: locked machine name, defaults from the entity.
   */
  public function testEditSurfaceLocksMachineName(): void {
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'help' => 'Some help.',
      'description' => 'Articles.',
    ])->save();
    $type = NodeType::load('article');
    $surface = $this->provider->surfaceFor($type);

    $this->assertTrue($surface->isLocked('type'));
    $this->assertSame('article', $surface->getDefault('type'));
    // No uniqueness check against yourself on edit.
    $this->assertArrayNotHasKey('DataSurfaceUniqueNodeType', $surface->getDefinition('type')->getConstraints());

    // Entity values are the surface defaults.
    $defaults = $surface->getDefaultValues();
    $this->assertSame('Article', $defaults['name']);
    $this->assertSame('Some help.', $defaults['help']);
    $this->assertSame('Articles.', $defaults['description']);

    // The generated form renders the locked value, disabled.
    $form = $this->container->get('data_surface.form_builder')
      ->buildSurfaceForm($surface, $defaults, new FormState());
    $this->assertTrue($form['type']['#disabled']);
    $this->assertSame('article', $form['type']['#default_value']);
    // The labels ride the constraint, so the select needs nothing
    // cosmetic to say what 0, 1 and 2 mean.
    $this->assertSame('select', $form['preview_mode']['#type']);
    $this->assertSame(
      ['Disabled', 'Optional', 'Required'],
      array_map('strval', array_values($form['preview_mode']['#options'])),
    );
    // The multiline setting is what makes these two textareas.
    $this->assertSame('textarea', $form['description']['#type']);
    $this->assertSame('textarea', $form['help']['#type']);

    // Extraction is authoritative from the surface: a tampered submit
    // cannot move a locked value.
    $form_state = new FormState();
    $form_state->setValues(['name' => 'Renamed', 'type' => 'evil_rename']);
    $values = $this->container->get('data_surface.form_builder')
      ->extractSurfaceValues($surface, $form, $form_state);
    $this->assertSame('article', $values['type']);
    $this->assertSame('Renamed', $values['name']);
  }

  /**
   * Tests submitting an add, then an edit, through the composite target.
   */
  public function testSubmitReachesEveryDestination(): void {
    $surface = $this->provider->surfaceFor();
    $input = [
      'name' => 'Recipe',
      'type' => 'recipe',
      'title_label' => 'Recipe name',
      'description' => 'Cooking instructions.',
      'help' => '',
      'preview_mode' => '2',
      'display_submitted' => 0,
      'new_revision' => 1,
      'status' => 1,
      'promote' => 0,
      'sticky' => 1,
    ];
    $result = $this->pipeline()->submit($surface, $input, $this->provider->targetFor(NULL, 'recipe'));

    $this->assertCount(0, $result->violations);
    $this->assertTrue($result->committed);

    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeTypeInterface::class, $type);
    $this->assertSame('Recipe', $type->label());
    $this->assertSame('Cooking instructions.', $type->getDescription());
    $this->assertSame(2, $type->getPreviewMode(FALSE)->value);
    $this->assertFalse($type->displaySubmitted());
    $this->assertTrue($type->shouldCreateNewRevision());

    // The title label and the workflow defaults landed as base field
    // overrides, not on the node type entity: the second destination
    // behind the one surface.
    $fields = $this->nodeFields('recipe');
    $this->assertSame('Recipe name', (string) $fields['title']->getLabel());
    $this->assertFalse((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);
    $this->assertTrue((bool) $fields['sticky']->getDefaultValueLiteral()[0]['value']);
    // Comparing before writing means the values that did not move wrote
    // no override at all.
    $this->assertNull($this->baseFieldOverride('promote'));
    $this->assertNotNull($this->baseFieldOverride('sticky'));

    // Edit through the same surface and the same pipeline: the changed
    // values round-trip to both destinations, and the machine name
    // cannot move.
    $edit_surface = $this->provider->surfaceFor($type);
    $edit_target = $this->provider->targetFor($type);
    $this->assertSame('Recipe name', $edit_target->load($edit_surface)['title_label']);
    $edit = $this->pipeline()->submit($edit_surface, [
      'name' => 'Recipes',
      'type' => 'tampered',
      'promote' => 1,
      'title_label' => 'Dish name',
    ], $edit_target);

    $this->assertCount(0, $edit->violations);
    $this->assertTrue($edit->committed);
    $this->assertSame('recipe', $edit->values['type']);

    $reloaded = NodeType::load('recipe');
    $this->assertSame('Recipes', $reloaded->label());
    // Everything the edit did not mention kept its stored value.
    $this->assertSame(2, $reloaded->getPreviewMode(FALSE)->value);
    $fields = $this->nodeFields('recipe');
    $this->assertSame('Dish name', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);
    $this->assertTrue((bool) $fields['sticky']->getDefaultValueLiteral()[0]['value']);
  }

  /**
   * Tests the config schema refusing what the surface let through.
   *
   * The node type schema is fully validatable, and the entity target is
   * left to hold the built entity to it. A label the surface only
   * limited in length is a label the schema also forbids line breaks in,
   * so the storage's second opinion arrives in the same violation shape
   * the surface's own constraints use, filed under the surface key.
   */
  public function testSchemaRefusesValuesTheSurfaceAllowed(): void {
    $surface = $this->provider->surfaceFor();
    $result = $this->pipeline()->submit($surface, [
      'name' => "Two\nlines",
      'type' => 'two_lines',
      'title_label' => 'Title',
    ], $this->provider->targetFor(NULL, 'two_lines'));

    $this->assertContains('name', $result->violations->keys());
    $this->assertFalse($result->committed);
    $this->assertNull(NodeType::load('two_lines'));
  }

  /**
   * Tests a dry run shaping every destination and writing none of them.
   */
  public function testDryRunWritesNothing(): void {
    $surface = $this->provider->surfaceFor();
    $result = $this->pipeline()->submit($surface, [
      'name' => 'Dry run',
      'type' => 'dry_run',
      'title_label' => 'Dry run title',
      'promote' => 1,
    ], $this->provider->targetFor(NULL, 'dry_run'), TRUE);

    $this->assertCount(0, $result->violations);
    $this->assertFalse($result->committed);

    // One prepared set per child, and inside the second one an unsaved
    // override per base field that would have to move: the node type
    // plus two overrides, all of it ready to show, none of it stored.
    $children = $result->prepared->artifact;
    $this->assertCount(2, $children);
    $this->assertInstanceOf(PreparedValues::class, $children[0]);
    $this->assertInstanceOf(NodeTypeInterface::class, $children[0]->artifact);
    $this->assertTrue($children[0]->artifact->isNew());
    $overrides = $children[1]->artifact[BaseFieldOverrideTarget::SAVE];
    $this->assertCount(2, $overrides);
    $this->assertSame([], $children[1]->artifact[BaseFieldOverrideTarget::DELETE]);
    foreach ($overrides as $override) {
      $this->assertTrue($override->isNew());
    }
    $this->assertSame(
      ['node.dry_run.title', 'node.dry_run.promote'],
      array_map(static fn ($override) => $override->id(), $overrides),
    );

    $this->assertNull(NodeType::load('dry_run'));
    $this->assertNull($this->baseFieldOverride('title', 'dry_run'));
    $this->assertNull($this->baseFieldOverride('promote', 'dry_run'));
  }

  /**
   * Tests the operation link, which is a hook class rather than a file.
   *
   * The demo's one procedural hook became a #[Hook] method, so this
   * asserts that core discovers it, that it holds the entity's own
   * access answer rather than a permission repeated in the module, and
   * that the answer's cacheability reaches the listing that renders it.
   */
  public function testOperationLinkComesFromTheHookClass(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node_type = NodeType::load('article');
    $module_handler = $this->container->get('module_handler');

    // Nobody in particular may edit a content type, and the link is the
    // entity's answer rather than a permission repeated in the module.
    $anonymous = new CacheableMetadata();
    $this->assertSame(
      [],
      $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', [$node_type, $anonymous]),
    );

    // Holding the content type permission alone is not enough. This
    // module is a second way into writing content types and has its own
    // permission for that, and the link asks both questions the route it
    // points at asks, so it is never offered where it would be refused.
    $this->setUpCurrentUser([], ['administer content types']);
    $this->assertSame(
      [],
      $module_handler->invoke('data_surface_demo_node_type', 'entity_operation', [$node_type, new CacheableMetadata()]),
    );

    $this->setUpCurrentUser([], ['administer content types', NodeTypeSurfaceHooks::PERMISSION]);
    $cacheability = new CacheableMetadata();
    $operations = $module_handler
      ->invoke('data_surface_demo_node_type', 'entity_operation', [$node_type, $cacheability]);

    $this->assertArrayHasKey('surface_edit', $operations);
    $this->assertSame('Edit (surface)', (string) $operations['surface_edit']['title']);
    $this->assertSame(
      '/admin/structure/types/manage/article/surface-edit',
      $operations['surface_edit']['url']->toString(),
    );
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());

    // Nothing is offered for an entity of another type.
    $account = $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->create(['name' => 'somebody']);
    $this->assertSame(
      [],
      $module_handler->invoke(
        'data_surface_demo_node_type',
        'entity_operation',
        [$account, new CacheableMetadata()],
      ),
    );
  }

  /**
   * Tests that both routes are gated by entity access and by permission.
   *
   * Both requirements have to allow, and neither alone opens anything:
   * entity access is what keeps this module from granting more than
   * core's own content type form grants, and the module's permission is
   * what decides whether this second way in exists on the site at all.
   */
  public function testRouteAccessIsGatedTwice(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $access_manager = $this->container->get('access_manager');
    // User 1 bypasses every access check and a kernel test has none
    // until one is asked for, so it is created and set aside before any
    // account below is made.
    $this->setUpCurrentUser();

    $matrix = [
      'nobody' => [[], FALSE],
      'content types only' => [['administer content types'], FALSE],
      'demo permission only' => [[NodeTypeSurfaceHooks::PERMISSION], FALSE],
      'both' => [['administer content types', NodeTypeSurfaceHooks::PERMISSION], TRUE],
    ];
    foreach ($matrix as $who => [$permissions, $allowed]) {
      $account = $this->createUser($permissions);
      $this->assertSame(
        $allowed,
        $access_manager->checkNamedRoute('data_surface_demo_node_type.add', [], $account),
        $who . ' on the add route.',
      );
      $this->assertSame(
        $allowed,
        $access_manager->checkNamedRoute('data_surface_demo_node_type.edit', ['node_type' => 'article'], $account),
        $who . ' on the edit route.',
      );
    }
  }

  /**
   * Tests that the provider's answer is the answer the routes give.
   *
   * The non-drift claim, asserted rather than asserted about: for every
   * account in the same four-way matrix the routes are held to, the
   * provider's answer for the matching operation and the route's answer
   * are the same answer. A permission added to one and not the other, or
   * an entity check quietly dropped from one side, fails here.
   */
  public function testProviderAccessMatchesTheRoutes(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $article = NodeType::load('article');
    $access_manager = $this->container->get('access_manager');
    // User 1 bypasses every access check and a kernel test has none
    // until one is asked for, so it is created and set aside first.
    $this->setUpCurrentUser();

    $matrix = [
      'nobody' => [[], FALSE],
      'entity access only' => [['administer content types'], FALSE],
      'demo permission only' => [[NodeTypeSurfaceHooks::PERMISSION], FALSE],
      'both' => [['administer content types', NodeTypeSurfaceHooks::PERMISSION], TRUE],
    ];
    foreach ($matrix as $who => [$permissions, $allowed]) {
      $account = $this->createUser($permissions);

      $add = $this->provider->surfaceAccess($this->provider->operationFor(), $account);
      $edit = $this->provider->surfaceAccess($this->provider->operationFor($article), $account);

      $this->assertSame($allowed, $add->isAllowed(), $who . ' on the add surface.');
      $this->assertSame($allowed, $edit->isAllowed(), $who . ' on the edit surface.');
      // The same answer the route gives, which is the whole point of
      // having the provider answer at all.
      $this->assertSame(
        $access_manager->checkNamedRoute('data_surface_demo_node_type.add', [], $account),
        $add->isAllowed(),
        $who . ' on the add route and the add surface.',
      );
      $this->assertSame(
        $access_manager->checkNamedRoute('data_surface_demo_node_type.edit', ['node_type' => 'article'], $account),
        $edit->isAllowed(),
        $who . ' on the edit route and the edit surface.',
      );
      // Permissions decide it, so the answer may only be reused for an
      // account holding the same ones.
      $this->assertContains(
        'user.permissions',
        CacheableMetadata::createFromObject($edit)->getCacheContexts(),
      );
    }
  }

  /**
   * Tests that an operation this provider has no surface for is refused.
   *
   * Asking for a surface that does not exist throws, and an access
   * question is the one question that must never be answered with an
   * exception: something has to be told no.
   */
  public function testAccessRefusesAnUnknownOperation(): void {
    $account = $this->createUser(['administer content types', NodeTypeSurfaceHooks::PERMISSION]);

    $this->assertTrue($this->provider->surfaceAccess('delete', $account)->isForbidden());
    // Including an edit operation naming a content type that is not
    // there, which is refused until it is created rather than forever.
    $missing = $this->provider->surfaceAccess(NodeTypeSurfaceProvider::OPERATION_EDIT_PREFIX . 'ghost', $account);
    $this->assertTrue($missing->isForbidden());
    $this->assertContains(
      'config:node_type_list',
      CacheableMetadata::createFromObject($missing)->getCacheTags(),
    );
  }

  /**
   * Tests that the form's write is gated by the provider's answer.
   *
   * The form is the caller that matters most, because a route requirement
   * is checked once, when the page is built, and a submit arrives later:
   * a permission revoked in between has to be caught by the half that
   * writes. So the pipeline refuses the same values it accepted for an
   * allowed account, and refuses them before the target is read.
   */
  public function testSubmitIsRefusedForAnAccountWithoutAccess(): void {
    $this->setUpCurrentUser();
    $stranger = $this->createUser();
    $surface = $this->provider->surfaceFor();
    $values = $surface->getDefaultValues();
    $values['name'] = 'Refused';
    $values['type'] = 'refused';
    $values['title_label'] = 'Title';

    $result = $this->pipeline()->submit(
      $surface,
      $values,
      $this->provider->targetFor(NULL, 'refused'),
      access: $this->provider->surfaceAccess($this->provider->operationFor(), $stranger),
    );

    $this->assertFalse($result->isValid());
    $this->assertTrue($result->isAccessRefused());
    $this->assertNull(NodeType::load('refused'));
    // The same values, for an account that may, are stored.
    $allowed = $this->createUser(['administer content types', NodeTypeSurfaceHooks::PERMISSION]);
    $result = $this->pipeline()->submit(
      $surface,
      $values,
      $this->provider->targetFor(NULL, 'refused'),
      access: $this->provider->surfaceAccess($this->provider->operationFor(), $allowed),
    );

    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $stored = $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->load('refused');
    $this->assertInstanceOf(NodeTypeInterface::class, $stored);
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
  protected function baseFieldOverride(string $field_name, string $bundle = 'recipe') {
    return $this->container->get('entity_type.manager')
      ->getStorage('base_field_override')
      ->load('node.' . $bundle . '.' . $field_name);
  }

}
