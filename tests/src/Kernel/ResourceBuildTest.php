<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\data_surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Structure\DemoBlockStructure;
use Drupal\data_surface_demo\Structure\GridPresentationStructure;
use Drupal\data_surface_demo\Structure\ListPresentationStructure;
use Drupal\data_surface_surface_test\Structure\Broken\AttachingStructure;
use Drupal\data_surface_surface_test\Structure\Broken\NosyChildStructure;
use Drupal\data_surface_surface_test\Structure\Broken\NosyParentStructure;
use Drupal\data_surface_surface_test\Structure\Broken\RefinesAttachedStructure;
use Drupal\data_surface_surface_test\Structure\Broken\SelfAttachingStructure;
use Drupal\data_surface_surface_test\Structure\Broken\UnofferedVariantStructure;
use Drupal\data_surface_surface_test\Structure\Broken\WatchesAttachedStructure;
use Drupal\data_surface_surface_test\Structure\Broken\ClashingOperationStructure;
use Drupal\data_surface_surface_test\Structure\Broken\InstanceAlterRefinerStructure;
use Drupal\data_surface_surface_test\Structure\Broken\InstanceRefinerStructure;
use Drupal\data_surface_surface_test\Structure\Broken\RefinesOutputStructure;
use Drupal\data_surface_surface_test\Resource\Broken\UndeclaredIdentityResource;
use Drupal\data_surface_surface_test\Structure\Broken\StrictMountWatcherStructure;
use Drupal\data_surface_surface_test\Structure\Broken\WatchesMismatchStructure;
use Drupal\data_surface_surface_test\Alter\InstanceRefinerAlter;
use Drupal\data_surface_surface_test\Alter\StrictMountWatcherAlter;
use Drupal\data_surface_surface_test\Structure\Broken\WatchesUndeclaredStructure;
use Drupal\data_surface_surface_test\Structure\Broken\WideningRefinerStructure;
use Drupal\data_surface_surface_test\Structure\DynamicChildStructure;
use Drupal\data_surface_surface_test\Structure\MountWatcherStructure;
use Drupal\data_surface_surface_test\Resource\RecipeResource;
use Drupal\data_surface_surface_test\Storage\RecipeStorage;
use Drupal\data_surface_test\Alter\ForeignMountWatcherAlter;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\data_surface\Kernel\Fixture\CountingResources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the build step: a class and a context in, a data surface out.
 *
 * The demo block's structure is the plugin host case. The recipe fixture
 * covers the rest: identity, operations, constraints and starting values
 * from a context, refiner dispatch, alters, access and the storage. The
 * broken fixtures each fail one seal-time check, by name.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceBuildTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'node',
    'field',
    'text',
    'data_surface',
    'data_surface_demo',
    'data_surface_surface_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The build step.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
  }

  /**
   * Builds the demo block's structure.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built structure DemoBlockStructure produces.
   */
  protected function demoStructure(): DataSurfaceInterface {
    return $this->resources()->build(DemoBlockStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests the demo structure's refinement edges, read off its signatures.
   */
  public function testDemoStructureRefinementEdges(): void {
    $this->assertSame([
      'bundle' => ['entity_type'],
      'field' => ['entity_type', 'bundle'],
      'presentation_settings' => ['presentation'],
    ], $this->demoStructure()->getDefinitions()->refinements());
  }

  /**
   * Tests the escape hatch: an `any` key a refiner narrows to a map.
   *
   * The dynamic-child case needs no verb: a #[RefinesInput] method on an
   * `any` key returns the narrower definition itself, held to the same
   * narrowing rule, and the pipeline judges values against it.
   */
  public function testAnyKeyRefinesToMap(): void {
    $surface = $this->resources()->build(DynamicChildStructure::class, new OperationContext('configure'));
    $this->assertSame('any', $surface->getDefinition('detail')->getDataType());

    $refined = $surface->refine(['kind' => 'box'])->getDefinition('detail');
    $this->assertInstanceOf(MapDataDefinition::class, $refined);
    $this->assertSame(['width'], array_keys($refined->getPropertyDefinitions()));

    $this->assertSame([], $this->pipeline()->validate($surface, ['kind' => 'box', 'detail' => ['width' => 3]])->keys());
    $this->assertContains('detail', $this->pipeline()->validate($surface, ['kind' => 'box', 'detail' => ['length' => 3]])->keys());
  }

  /**
   * Tests that the block host builds the structure #[UsesStructure] names.
   *
   * Once, through the build step; the presentation slot's children are
   * built inside that one build, each in its own frame.
   */
  public function testBlockHostBuildsTheUsedStructure(): void {
    $counting = new CountingResources($this->resources());
    $this->container->set('data_surface.resources', $counting);
    $definition = $this->container->get('plugin.manager.block')->getDefinition('data_surface_demo');
    $this->assertSame(DemoBlockStructure::class, $definition[UsesStructure::DEFINITION_KEY]);

    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    $this->assertSame([DemoBlockStructure::class], $counting->builds);
    $this->assertSame(
      array_keys($this->demoStructure()->getDefinitions()->toArray()),
      array_keys($block->getDataSurface()->getDefinitions()->toArray()),
    );
    $slot = $block->getDataSurface()->getDefinitions()->entry('presentation_settings')?->slot;
    $this->assertNotNull($slot);
    $this->assertSame(ListPresentationStructure::class, $slot->variant('list')->source);
    $this->assertSame(GridPresentationStructure::class, $slot->variant('grid')->source);
  }

  /**
   * Tests that the identity a context knows is locked, and the rest open.
   */
  public function testKnownIdentityIsLocked(): void {
    $add = $this->resources()->buildOperation(RecipeResource::class, 'add', ['main']);
    $this->assertTrue($add->isLocked('kitchen'));
    $this->assertSame('main', $add->getDefault('kitchen'));
    $this->assertFalse($add->isLocked('name'));

    $edit = $this->resources()->buildOperation('surface_test.recipe', 'edit', ['kitchen' => 'main', 'name' => 'stew']);
    $this->assertTrue($edit->isLocked('kitchen'));
    $this->assertTrue($edit->isLocked('name'));
    $this->assertSame('stew', $edit->getDefault('name'));

    // A generic caller passes an empty context: nothing is locked, and it
    // is still the same resource.
    $open = $this->resources()->build(RecipeResource::class, new OperationContext('add'));
    $this->assertFalse($open->isLocked('kitchen'));
    $this->assertSame(array_keys($add->getDefinitions()->toArray()), array_keys($open->getDefinitions()->toArray()));
  }

  /**
   * Tests that a context's constraints narrow, and may not widen.
   */
  public function testContextConstraintsNarrow(): void {
    $context = RecipeResource::add('main')->withConstraint('servings', 'Range', ['min' => 2, 'max' => 12]);
    $surface = $this->resources()->build(RecipeResource::class, $context);
    $this->assertSame(['min' => 2, 'max' => 12], $surface->getDefinition('servings')->getConstraints()['Range']);
    $this->assertContains('servings', $this->pipeline()->validate($surface, ['servings' => 1] + $surface->getDefaultValues() + ['name' => 'x'])->keys());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "servings" for the "add" operation widened what it was given: the max of the Range constraint moved from 12 to 99.');
    $widening = RecipeResource::add('main')->withConstraint('servings', 'Range', [
      'min' => 1,
      'max' => 99,
    ]);
    $this->resources()->build(RecipeResource::class, $widening);
  }

  /**
   * Tests that starting values become the defaults of a creating context.
   */
  public function testStartingValuesAreDefaults(): void {
    $context = $this->resources()->operation(RecipeResource::class, 'clone', ['main', 'dessert', 'cake']);
    $this->assertSame('clone', $context->id);
    $this->assertTrue($context->creates);
    $surface = $this->resources()->build(RecipeResource::class, $context);
    $this->assertSame('dessert', $surface->getDefault('course'));
    $this->assertSame('cake', $surface->getDefault('dish'));
    // Initial values, not locks.
    $this->assertFalse($surface->isLocked('course'));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "edit" context carries starting values for course but does not create.');
    $this->resources()->build(RecipeResource::class, RecipeResource::edit('main', 'stew')->withStarting(['course' => 'starter']));
  }

  /**
   * Tests refiner dispatch: by name, by watches, owner first, then alters.
   */
  public function testRefinerDispatch(): void {
    $surface = $this->resources()->build(RecipeResource::class, RecipeResource::add('main'));
    $choices = static fn (DataSurfaceInterface $refined): array => $refined->getDefinition('dish')->getConstraints()['Choice']['choices'];

    // The dish is refined by the owner (watching course, by name) and by
    // the alter (watching vegetarian). The engine gates per key, so it
    // runs once both have values; until then it is as advertised.
    $this->assertSame(['dish' => ['course', 'vegetarian'], 'servings' => ['course', 'vegetarian']], $surface->getDefinitions()->refinements());
    $this->assertCount(5, $choices($surface->refine(['course' => 'main'])));
    $this->assertSame(['fish', 'risotto'], $choices($surface->refine(['course' => 'main', 'vegetarian' => FALSE])));
    // The owner narrows to the course, then the alter takes the fish out.
    $this->assertSame(['risotto'], $choices($surface->refine(['course' => 'main', 'vegetarian' => TRUE])));

    // Two watched siblings, in parameter order, each arriving as the type
    // the parameter declares even from raw form input.
    $range = static fn (array $values): array => $surface->refine($values)->getDefinition('servings')->getConstraints()['Range'];
    $this->assertSame(['min' => 1, 'max' => 12], $range(['course' => 'main', 'vegetarian' => '0']));
    $this->assertSame(['min' => 1, 'max' => 6], $range(['course' => 'dessert', 'vegetarian' => '1']));

    // A refiner that watches nothing ran once, at build.
    $this->assertSame(['max' => 40], $surface->getDefinition('name')->getConstraints()['Length']);

    // The refiners ride inside the built resource, alter service and all.
    // The resource's own ride as its class name: its refiners are static,
    // and nothing ever instantiated the class to carry.
    $serialized = serialize($surface);
    $this->assertStringNotContainsString('O:' . strlen(RecipeResource::class) . ':"' . RecipeResource::class . '"', $serialized);
    $this->assertStringContainsString('"' . RecipeResource::class . '"', $serialized);
    // A cached form restores the built resource whole, so this allows every
    // class the way core's own form cache does.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize($serialized);
    $this->assertSame(['risotto'], $choices($restored->refine(['course' => 'main', 'vegetarian' => TRUE])));
  }

  /**
   * Tests every seal-time check, each naming the offender.
   *
   * @param class-string $resource
   *   The broken class.
   * @param string $message
   *   What the refusal says.
   */
  #[DataProvider('brokenClasses')]
  public function testSealTimeChecks(string $resource, string $message): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage($message);
    $this->resources()->build($resource, new OperationContext('configure'));
  }

  /**
   * Supplies the broken classes and what each is refused for.
   *
   * @return array
   *   Cases.
   */
  public static function brokenClasses(): array {
    return [
      'identity never declared' => [
        UndeclaredIdentityResource::class,
        'The surface_test.broken.identity resource (' . UndeclaredIdentityResource::class . ') names "ghost" as an identity key in #[Resource(identity:)], but its shape never declares that input.',
      ],
      'watched key not declared' => [
        WatchesUndeclaredStructure::class,
        WatchesUndeclaredStructure::class . '::nameOfGhost() watches "ghost", which surface_test.broken.watches_undeclared does not declare as an input.',
      ],
      'refines an output' => [
        RefinesOutputStructure::class,
        RefinesOutputStructure::class . '::totalOfCount() refines "total", which is an output of surface_test.broken.refines_output.',
      ],
      'an alter watches a key it mounted, not taking NULL' => [
        StrictMountWatcherStructure::class,
        StrictMountWatcherAlter::class . '::sizeOfKind() watches "kind", a key its alter mounted on surface_test.broken.strict_mount_watcher, which is handed as it stands and is NULL until answered: declare $kind nullable.',
      ],
      'watches out of step with the parameters' => [
        WatchesMismatchStructure::class,
        WatchesMismatchStructure::class . '::thirdOf() lists watches [first, second], but its parameters after the definition are ($first, $renamed).',
      ],
      'two providers of one operation' => [
        ClashingOperationStructure::class,
        'surface_test.broken.clashing_operation has two operations with the id "open", from ' . ClashingOperationStructure::class . '::open() in data_surface_surface_test and from Drupal\data_surface_surface_test\Alter\ClashingOperations::open() in data_surface_surface_test.',
      ],
      'a refiner that watches nothing widens' => [
        WideningRefinerStructure::class,
        'Refining "name" for ' . WideningRefinerStructure::class . '::nameIsOptional() widened what it was given: the required flag was turned off.',
      ],
      'a parent refines its attached structure' => [
        RefinesAttachedStructure::class,
        RefinesAttachedStructure::class . '::intoTheShelf() refines "shelf", which holds a child in surface_test.broken.refines_subsurface.',
      ],
      'a parent watches its attached structure' => [
        WatchesAttachedStructure::class,
        WatchesAttachedStructure::class . '::noteForShelf() watches "shelf", which holds a child in surface_test.broken.watches_subsurface.',
      ],
      'a child watches its parent' => [
        NosyParentStructure::class,
        NosyChildStructure::class . '::heightInPantry() watches "pantry", which is a key of surface_test.broken.nosy_parent, the parent this one is attached inside at "nosy".',
      ],
      'a variant its deciding key cannot choose' => [
        UnofferedVariantStructure::class,
        'The "settings" slot has the tin variant, which "kind" does not allow',
      ],
      'an owner refiner that is an instance method' => [
        InstanceRefinerStructure::class,
        InstanceRefinerStructure::class . '::nameOfKind() is a #[RefinesInput] method of surface_test.broken.instance_refiner and is not static.',
      ],
      'an alter refiner that is an instance method and not runtime' => [
        InstanceAlterRefinerStructure::class,
        InstanceRefinerAlter::class . '::nameOfKind() is an instance method of an alter of surface_test.broken.instance_alter_refiner and does not declare runtime: TRUE.',
      ],
      'a structure inside itself' => [
        SelfAttachingStructure::class,
        'surface_test.broken.self_attaching is attached inside itself, through surface_test.broken.self_attaching.again',
      ],
    ];
  }

  /**
   * Tests an alter watches the keys it mounted, and no other module's.
   *
   * Its own kind, read at its path inside the mount and handed as it
   * stands, narrows its own detail and the owner's size; the same kind,
   * watched by an alter of another module, is refused.
   */
  public function testAnAlterWatchesOnlyTheKeysItMounted(): void {
    $kind = 'third_party_settings.data_surface_surface_test.kind';
    $surface = $this->resources()->build(MountWatcherStructure::class, new OperationContext('configure'));
    $definitions = $surface->getDefinitions();
    $this->assertSame(['size' => [$kind], 'third_party_settings' => [$kind]], $definitions->refinements());
    $this->assertSame(['size' => [$kind], 'third_party_settings.data_surface_surface_test.detail' => [$kind]], $definitions->refinementPaths());

    // Unanswered, the kind holds nothing back: it is handed as NULL.
    $detail = fn (array $values): array => $this->mounted($surface->refine($values)->getDefinition('third_party_settings'))['detail']->getConstraints();
    $this->assertSame(['max' => 10], $detail([])['Length']);
    $with = ['third_party_settings' => ['data_surface_surface_test' => ['kind' => 'large']]];
    $this->assertArrayNotHasKey('Length', $detail($with));
    $this->assertSame(['max' => 100], $surface->refine($with)->getDefinition('size')->getConstraints()['Range']);
    $this->assertArrayNotHasKey('Range', $surface->refine([])->getDefinition('size')->getConstraints());

    $this->enableModules(['data_surface_test']);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(ForeignMountWatcherAlter::class . '::noteOfKind() watches "kind", a key the data_surface_surface_test module mounted on surface_test.mount_watcher. An alter may watch the keys it mounted itself and the owner\'s, never another module\'s.');
    $this->container->get('data_surface.resources')->build(MountWatcherStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests an open slot nothing fills.
   *
   * It is a placeholder for good, and its deciding key can be answered
   * with nothing at all, rather than the structure being refused on a site
   * where no module brings a variant.
   */
  public function testAnOpenSlotNothingFillsChoosesNothing(): void {
    $surface = $this->resources()->build(AttachingStructure::class, new OperationContext('configure'));
    $this->assertNull(DefinitionMetadata::slotOf($surface->getDefinition('type')));
    $this->assertSame('type', DefinitionMetadata::slotOf($surface->getDefinition('settings')));
    $this->assertSame([], $surface->getDefinitions()->entry('settings')->slot->variantIds());
    $this->assertSame(['choices' => []], $surface->getDefinition('type')->getConstraints()['Choice']);
    $this->assertContains('type', $this->pipeline()->validate($surface, ['type' => 'anything'])->keys());
  }

  /**
   * Tests that an alter's attach, and an output's, are refused for now.
   */
  public function testAttachIsTheOwnersInputVerbForNow(): void {
    $builder = new DataSurfaceBuilder();
    $outputs = new ResourceBlueprint($builder, $this->container->get('typed_data_manager'), TRUE);
    try {
      $outputs->attach('storage', RecipeResource::class);
      $this->fail('An output attached a child.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('outputs do not hold attached structures yet', $e->getMessage());
    }
    $additions = new ResourceBlueprintAdditions($builder, $this->container->get('typed_data_manager'), 'data_surface_surface_test');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('an attached structure inside that mount is not built');
    $additions->attach('storage', RecipeResource::class);
  }

  /**
   * Tests that adding a key twice is refused rather than replacing it.
   */
  public function testAddingIsNeverReplacing(): void {
    $blueprint = new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'));
    $blueprint->add('name', 'string', 'Name');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The input "name" was already added to this blueprint.');
    $blueprint->add('name', 'string', 'Other name');
  }

  /**
   * Tests that alters add inputs and outputs, in the operations they name.
   */
  public function testAltersApply(): void {
    $add = $this->resources()->buildOperation(RecipeResource::class, 'add', ['main']);
    $this->assertSame(['garnish'], array_keys($this->mounted($add->getDefinition('third_party_settings'))));
    $this->assertSame('parsley', $add->getDefaultValues()['third_party_settings']['data_surface_surface_test']['garnish']);
    $outputs = $add->getOutputDefinitions()->toArray();
    $this->assertSame(['id', 'third_party_outputs'], array_keys($outputs));
    $this->assertSame(['plated'], array_keys($this->mounted($outputs['third_party_outputs'])));

    // The edit-only alter applies where it says, and nowhere else. Alters
    // apply in discovery order, which is class name order within a module.
    $edit = $this->resources()->buildOperation(RecipeResource::class, 'edit', ['main', 'stew']);
    $this->assertSame(['revision_note', 'garnish'], array_keys($this->mounted($edit->getDefinition('third_party_settings'))));
  }

  /**
   * Tests operation lookup and the contract an operation keeps.
   */
  public function testOperations(): void {
    $operations = $this->container->get('data_surface.resource_registry')->getOperations(RecipeResource::class);
    $this->assertSame(['add', 'edit', 'clone', 'mislabeled'], array_keys($operations));
    $this->assertSame('data_surface_surface_test', $operations['clone']->module);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('returned a context whose id is "add"');
    $this->resources()->operation(RecipeResource::class, 'mislabeled', ['main']);
  }

  /**
   * Tests the two access tiers.
   */
  public function testAccess(): void {
    $cook = $this->account(['cook in main', 'cook in closed']);
    $stranger = $this->account([]);
    $resources = $this->resources();

    // The permission, its placeholder filled from the known kitchen,
    // then the access class.
    $this->assertTrue($resources->access(RecipeResource::class, RecipeResource::edit('main', 'stew'), $cook)->isAllowed());
    $this->assertFalse($resources->access(RecipeResource::class, RecipeResource::edit('main', 'stew'), $stranger)->isAllowed());
    $this->assertFalse($resources->access(RecipeResource::class, RecipeResource::add('pantry'), $cook)->isAllowed());
    // The permission passes; the access class, which reads the subject,
    // does not.
    $this->assertTrue($resources->access(RecipeResource::class, RecipeResource::edit('closed', 'stew'), $cook)->isForbidden());
    // A placeholder the context cannot fill is not a permission anybody
    // has.
    $this->assertTrue($resources->access(RecipeResource::class, new OperationContext('add'), $cook)->isForbidden());
    // A context that is no operation has no permission tier.
    $this->assertTrue($resources->access(RecipeResource::class, new OperationContext('configure'), $stranger)->isAllowed());
    $this->assertTrue($resources->access(DemoBlockStructure::class, new OperationContext('configure'), $stranger)->isNeutral());
  }

  /**
   * Tests that the resource's storage is reached through the pipeline.
   */
  public function testStorageThroughThePipeline(): void {
    $context = RecipeResource::add('main');
    $surface = $this->resources()->build(RecipeResource::class, $context);
    $result = $this->pipeline()->submit($surface, [
      'kitchen' => 'main',
      'name' => 'stew',
      'course' => 'main',
      'dish' => 'risotto',
      'servings' => '4',
      'vegetarian' => '1',
    ], $this->resources()->storage(RecipeResource::class, $context));
    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $stored = $this->container->get('state')->get(RecipeStorage::key('main', 'stew'));
    $this->assertSame(4, $stored['servings']);
    $this->assertSame('risotto', $stored['dish']);

    $edit = RecipeResource::edit('main', 'stew');
    $loaded = $this->resources()->storage(RecipeResource::class, $edit)->load($this->resources()->build(RecipeResource::class, $edit));
    $this->assertSame('risotto', $loaded['dish']);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('block.data_surface_demo is a structure, which has no storage of its own');
    $this->resources()->storage(DemoBlockStructure::class, new OperationContext('configure'));
  }

  /**
   * Gets what the fixture module mounted in a third-party map.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $map
   *   The third_party_settings or third_party_outputs definition.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The mounted definitions, keyed by key.
   */
  protected function mounted(?DataDefinitionInterface $map): array {
    $this->assertInstanceOf(MapDataDefinition::class, $map);
    $provider = $map->getPropertyDefinition('data_surface_surface_test');
    $this->assertInstanceOf(MapDataDefinition::class, $provider);
    return $provider->getPropertyDefinitions();
  }

  /**
   * Makes an account holding exactly some permissions.
   *
   * @param string[] $permissions
   *   The permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function account(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    $account->method('id')->willReturn(2);
    $account->method('getRoles')->willReturn(['authenticated']);
    return $account;
  }

}
