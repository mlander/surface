<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfaceShape;
use Drupal\data_surface\SurfaceBuild\SurfaceShapeAdditions;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;
use Drupal\data_surface_demo\Surface\GridPresentationSurface;
use Drupal\data_surface_demo\Surface\ListPresentationSurface;
use Drupal\data_surface_surface_test\Surface\Broken\AttachingSurface;
use Drupal\data_surface_surface_test\Surface\Broken\NosyChildSurface;
use Drupal\data_surface_surface_test\Surface\Broken\NosyParentSurface;
use Drupal\data_surface_surface_test\Surface\Broken\RefinesSubsurfaceSurface;
use Drupal\data_surface_surface_test\Surface\Broken\SelfAttachingSurface;
use Drupal\data_surface_surface_test\Surface\Broken\UnofferedVariantSurface;
use Drupal\data_surface_surface_test\Surface\Broken\WatchesSubsurfaceSurface;
use Drupal\data_surface_surface_test\Surface\Broken\ClashingSituationSurface;
use Drupal\data_surface_surface_test\Surface\Broken\InstanceRefinerSurface;
use Drupal\data_surface_surface_test\Surface\Broken\RefinesOutputSurface;
use Drupal\data_surface_surface_test\Surface\Broken\UndeclaredIdentitySurface;
use Drupal\data_surface_surface_test\Surface\Broken\StrictMountWatcherSurface;
use Drupal\data_surface_surface_test\Surface\Broken\WatchesMismatchSurface;
use Drupal\data_surface_surface_test\SurfaceAlter\StrictMountWatcherAlter;
use Drupal\data_surface_surface_test\Surface\Broken\WatchesUndeclaredSurface;
use Drupal\data_surface_surface_test\Surface\Broken\WideningRefinerSurface;
use Drupal\data_surface_surface_test\Surface\DynamicChildSurface;
use Drupal\data_surface_surface_test\Surface\MountWatcherSurface;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;
use Drupal\data_surface_surface_test\Target\RecipeTarget;
use Drupal\data_surface_test\SurfaceAlter\ForeignMountWatcherAlter;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\data_surface\Kernel\Fixture\CountingSurfaces;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the build step: a surface class and a context in, a surface out.
 *
 * The demo block's surface is the plugin host case. The recipe fixture
 * covers the rest: identity, situations, constraints and starting values
 * from a context, refiner dispatch, alters, access and the target. The
 * broken fixtures each fail one seal-time check, by name.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceBuildTest extends DataSurfaceKernelTestBase {

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
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The build step.
   */
  protected function surfaces(): SurfacesInterface {
    return $this->container->get('data_surface.surfaces');
  }

  /**
   * Builds the demo block's surface.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface DemoBlockSurface produces.
   */
  protected function demoSurface(): DataSurfaceInterface {
    return $this->surfaces()->build(DemoBlockSurface::class, new SurfaceContext('configure'));
  }

  /**
   * Tests the demo surface's refinement edges, read off its signatures.
   */
  public function testDemoSurfaceRefinementEdges(): void {
    $this->assertSame([
      'bundle' => ['entity_type'],
      'field' => ['entity_type', 'bundle'],
      'presentation_settings' => ['presentation'],
    ], $this->demoSurface()->getDefinitions()->refinements());
  }

  /**
   * Tests the escape hatch: an `any` key a refiner narrows to a map.
   *
   * The dynamic-child case needs no verb: a #[RefinesInput] method on an
   * `any` key returns the narrower definition itself, held to the same
   * narrowing rule, and the pipeline judges values against it.
   */
  public function testAnyKeyRefinesToMap(): void {
    $surface = $this->surfaces()->build(DynamicChildSurface::class, new SurfaceContext('configure'));
    $this->assertSame('any', $surface->getDefinition('detail')->getDataType());

    $refined = $surface->refine(['kind' => 'box'])->getDefinition('detail');
    $this->assertInstanceOf(MapDataDefinition::class, $refined);
    $this->assertSame(['width'], array_keys($refined->getPropertyDefinitions()));

    $this->assertSame([], $this->pipeline()->validate($surface, ['kind' => 'box', 'detail' => ['width' => 3]])->keys());
    $this->assertContains('detail', $this->pipeline()->validate($surface, ['kind' => 'box', 'detail' => ['length' => 3]])->keys());
  }

  /**
   * Tests that the block host builds the surface #[UsesSurface] names.
   *
   * Once, through the build step; the presentation slot's children are
   * built inside that one build, each in its own frame.
   */
  public function testBlockHostBuildsTheUsedSurface(): void {
    $counting = new CountingSurfaces($this->surfaces());
    $this->container->set('data_surface.surfaces', $counting);
    $definition = $this->container->get('plugin.manager.block')->getDefinition('data_surface_demo');
    $this->assertSame(DemoBlockSurface::class, $definition[UsesSurface::DEFINITION_KEY]);

    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    $this->assertSame([DemoBlockSurface::class], $counting->builds);
    $this->assertSame(
      array_keys($this->demoSurface()->getDefinitions()->toArray()),
      array_keys($block->getDataSurface()->getDefinitions()->toArray()),
    );
    $slot = $block->getDataSurface()->getDefinitions()->entry('presentation_settings')?->slot;
    $this->assertNotNull($slot);
    $this->assertSame(ListPresentationSurface::class, $slot->variant('list')->source);
    $this->assertSame(GridPresentationSurface::class, $slot->variant('grid')->source);
  }

  /**
   * Tests that the identity a context knows is locked, and the rest open.
   */
  public function testKnownIdentityIsLocked(): void {
    $add = $this->surfaces()->buildSituation(RecipeSurface::class, 'add', ['main']);
    $this->assertTrue($add->isLocked('kitchen'));
    $this->assertSame('main', $add->getDefault('kitchen'));
    $this->assertFalse($add->isLocked('name'));

    $edit = $this->surfaces()->buildSituation('surface_test.recipe', 'edit', ['kitchen' => 'main', 'name' => 'stew']);
    $this->assertTrue($edit->isLocked('kitchen'));
    $this->assertTrue($edit->isLocked('name'));
    $this->assertSame('stew', $edit->getDefault('name'));

    // A generic caller passes an empty context: nothing is locked, and it
    // is still the same surface.
    $open = $this->surfaces()->build(RecipeSurface::class, new SurfaceContext('add'));
    $this->assertFalse($open->isLocked('kitchen'));
    $this->assertSame(array_keys($add->getDefinitions()->toArray()), array_keys($open->getDefinitions()->toArray()));
  }

  /**
   * Tests that a context's constraints narrow, and may not widen.
   */
  public function testContextConstraintsNarrow(): void {
    $context = RecipeSurface::add('main')->withConstraint('servings', 'Range', ['min' => 2, 'max' => 12]);
    $surface = $this->surfaces()->build(RecipeSurface::class, $context);
    $this->assertSame(['min' => 2, 'max' => 12], $surface->getDefinition('servings')->getConstraints()['Range']);
    $this->assertContains('servings', $this->pipeline()->validate($surface, ['servings' => 1] + $surface->getDefaultValues() + ['name' => 'x'])->keys());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "servings" for the "add" situation widened what it was given: the max of the Range constraint moved from 12 to 99.');
    $widening = RecipeSurface::add('main')->withConstraint('servings', 'Range', [
      'min' => 1,
      'max' => 99,
    ]);
    $this->surfaces()->build(RecipeSurface::class, $widening);
  }

  /**
   * Tests that starting values become the defaults of a creating context.
   */
  public function testStartingValuesAreDefaults(): void {
    $context = $this->surfaces()->situation(RecipeSurface::class, 'clone', ['main', 'dessert', 'cake']);
    $this->assertSame('clone', $context->operation);
    $this->assertTrue($context->creates);
    $surface = $this->surfaces()->build(RecipeSurface::class, $context);
    $this->assertSame('dessert', $surface->getDefault('course'));
    $this->assertSame('cake', $surface->getDefault('dish'));
    // Initial values, not locks.
    $this->assertFalse($surface->isLocked('course'));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "edit" context carries starting values for course but does not create.');
    $this->surfaces()->build(RecipeSurface::class, RecipeSurface::edit('main', 'stew')->withStarting(['course' => 'starter']));
  }

  /**
   * Tests refiner dispatch: by name, by watches, owner first, then alters.
   */
  public function testRefinerDispatch(): void {
    $surface = $this->surfaces()->build(RecipeSurface::class, RecipeSurface::add('main'));
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

    // The refiners ride inside the surface, alter service and all. The
    // surface's own ride as its class name: its refiners are static, and
    // nothing ever instantiated the surface to carry.
    $serialized = serialize($surface);
    $this->assertStringNotContainsString('O:' . strlen(RecipeSurface::class) . ':"' . RecipeSurface::class . '"', $serialized);
    $this->assertStringContainsString('"' . RecipeSurface::class . '"', $serialized);
    // A cached form restores the surface whole, so this allows every
    // class the way core's own form cache does.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize($serialized);
    $this->assertSame(['risotto'], $choices($restored->refine(['course' => 'main', 'vegetarian' => TRUE])));
  }

  /**
   * Tests every seal-time check, each naming the offender.
   *
   * @param class-string $surface
   *   The broken surface.
   * @param string $message
   *   What the refusal says.
   */
  #[DataProvider('brokenSurfaces')]
  public function testSealTimeChecks(string $surface, string $message): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage($message);
    $this->surfaces()->build($surface, new SurfaceContext('configure'));
  }

  /**
   * Supplies the broken surfaces and what each is refused for.
   *
   * @return array
   *   Cases.
   */
  public static function brokenSurfaces(): array {
    return [
      'identity never declared' => [
        UndeclaredIdentitySurface::class,
        'The surface_test.broken.identity surface (' . UndeclaredIdentitySurface::class . ') names "ghost" as an identity key in #[Surface(identity:)], but its shape never declares that input.',
      ],
      'watched key not declared' => [
        WatchesUndeclaredSurface::class,
        WatchesUndeclaredSurface::class . '::nameOfGhost() watches "ghost", which the surface_test.broken.watches_undeclared surface does not declare as an input.',
      ],
      'refines an output' => [
        RefinesOutputSurface::class,
        RefinesOutputSurface::class . '::totalOfCount() refines "total", which is an output of the surface_test.broken.refines_output surface.',
      ],
      'an alter watches a key it mounted, not taking NULL' => [
        StrictMountWatcherSurface::class,
        StrictMountWatcherAlter::class . '::sizeOfKind() watches "kind", a key its alter mounted on the surface_test.broken.strict_mount_watcher surface, which is handed as it stands and is NULL until answered: declare $kind nullable.',
      ],
      'watches out of step with the parameters' => [
        WatchesMismatchSurface::class,
        WatchesMismatchSurface::class . '::thirdOf() lists watches [first, second], but its parameters after the definition are ($first, $renamed).',
      ],
      'two providers of one situation' => [
        ClashingSituationSurface::class,
        'The surface_test.broken.clashing_situation surface has two situations with the id "open", from ' . ClashingSituationSurface::class . '::open() in data_surface_surface_test and from Drupal\data_surface_surface_test\SurfaceAlter\ClashingSituations::open() in data_surface_surface_test.',
      ],
      'a refiner that watches nothing widens' => [
        WideningRefinerSurface::class,
        'Refining "name" for ' . WideningRefinerSurface::class . '::nameIsOptional() widened what it was given: the required flag was turned off.',
      ],
      'a parent refines its subsurface' => [
        RefinesSubsurfaceSurface::class,
        RefinesSubsurfaceSurface::class . '::intoTheShelf() refines "shelf", which is a subsurface of the surface_test.broken.refines_subsurface surface.',
      ],
      'a parent watches its subsurface' => [
        WatchesSubsurfaceSurface::class,
        WatchesSubsurfaceSurface::class . '::noteForShelf() watches "shelf", which is a subsurface of the surface_test.broken.watches_subsurface surface.',
      ],
      'a child watches its parent' => [
        NosyParentSurface::class,
        NosyChildSurface::class . '::heightInPantry() watches "pantry", which is a key of the surface_test.broken.nosy_parent surface this one is attached inside at "nosy".',
      ],
      'a variant its deciding key cannot choose' => [
        UnofferedVariantSurface::class,
        'The "settings" slot has the tin variant, which "kind" does not allow',
      ],
      'a surface refiner that is an instance method' => [
        InstanceRefinerSurface::class,
        InstanceRefinerSurface::class . '::nameOfKind() is a #[RefinesInput] method of the surface_test.broken.instance_refiner surface and is not static.',
      ],
      'a surface inside itself' => [
        SelfAttachingSurface::class,
        'The surface_test.broken.self_attaching surface is attached inside itself, through surface_test.broken.self_attaching.again',
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
    $surface = $this->surfaces()->build(MountWatcherSurface::class, new SurfaceContext('configure'));
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
    $this->expectExceptionMessage(ForeignMountWatcherAlter::class . '::noteOfKind() watches "kind", a key the data_surface_surface_test module mounted on the surface_test.mount_watcher surface. An alter may watch the keys it mounted itself and the owner\'s, never another module\'s.');
    $this->container->get('data_surface.surfaces')->build(MountWatcherSurface::class, new SurfaceContext('configure'));
  }

  /**
   * Tests an open slot nothing fills.
   *
   * It is a placeholder for good, and its deciding key can be answered
   * with nothing at all, rather than the surface being refused on a site
   * where no module brings a variant.
   */
  public function testAnOpenSlotNothingFillsChoosesNothing(): void {
    $surface = $this->surfaces()->build(AttachingSurface::class, new SurfaceContext('configure'));
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
    $outputs = new SurfaceShape($builder, $this->container->get('typed_data_manager'), TRUE);
    try {
      $outputs->attach('storage', RecipeSurface::class);
      $this->fail('An output attached a subsurface.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('outputs do not hold subsurfaces yet', $e->getMessage());
    }
    $additions = new SurfaceShapeAdditions($builder, $this->container->get('typed_data_manager'), 'data_surface_surface_test');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('a subsurface inside that mount is not built');
    $additions->attach('storage', RecipeSurface::class);
  }

  /**
   * Tests that adding a key twice is refused rather than replacing it.
   */
  public function testAddingIsNeverReplacing(): void {
    $shape = new SurfaceShape(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'));
    $shape->add('name', 'string', 'Name');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The input "name" was already added to this shape.');
    $shape->add('name', 'string', 'Other name');
  }

  /**
   * Tests that alters add inputs and outputs, in the situations they name.
   */
  public function testAltersApply(): void {
    $add = $this->surfaces()->buildSituation(RecipeSurface::class, 'add', ['main']);
    $this->assertSame(['garnish'], array_keys($this->mounted($add->getDefinition('third_party_settings'))));
    $this->assertSame('parsley', $add->getDefaultValues()['third_party_settings']['data_surface_surface_test']['garnish']);
    $outputs = $add->getOutputDefinitions()->toArray();
    $this->assertSame(['id', 'third_party_outputs'], array_keys($outputs));
    $this->assertSame(['plated'], array_keys($this->mounted($outputs['third_party_outputs'])));

    // The edit-only alter applies where it says, and nowhere else. Alters
    // apply in discovery order, which is class name order within a module.
    $edit = $this->surfaces()->buildSituation(RecipeSurface::class, 'edit', ['main', 'stew']);
    $this->assertSame(['revision_note', 'garnish'], array_keys($this->mounted($edit->getDefinition('third_party_settings'))));
  }

  /**
   * Tests situation lookup and the contract a situation keeps.
   */
  public function testSituations(): void {
    $situations = $this->container->get('data_surface.surface_registry')->getSituations(RecipeSurface::class);
    $this->assertSame(['add', 'edit', 'clone', 'mislabeled'], array_keys($situations));
    $this->assertSame('data_surface_surface_test', $situations['clone']->module);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('returned a context for the "add" operation');
    $this->surfaces()->situation(RecipeSurface::class, 'mislabeled', ['main']);
  }

  /**
   * Tests the two access tiers.
   */
  public function testAccess(): void {
    $cook = $this->account(['cook in main', 'cook in closed']);
    $stranger = $this->account([]);
    $surfaces = $this->surfaces();

    // The permission, its placeholder filled from the known kitchen,
    // then the access class.
    $this->assertTrue($surfaces->access(RecipeSurface::class, RecipeSurface::edit('main', 'stew'), $cook)->isAllowed());
    $this->assertFalse($surfaces->access(RecipeSurface::class, RecipeSurface::edit('main', 'stew'), $stranger)->isAllowed());
    $this->assertFalse($surfaces->access(RecipeSurface::class, RecipeSurface::add('pantry'), $cook)->isAllowed());
    // The permission passes; the access class, which reads the subject,
    // does not.
    $this->assertTrue($surfaces->access(RecipeSurface::class, RecipeSurface::edit('closed', 'stew'), $cook)->isForbidden());
    // A placeholder the context cannot fill is not a permission anybody
    // has.
    $this->assertTrue($surfaces->access(RecipeSurface::class, new SurfaceContext('add'), $cook)->isForbidden());
    // A context that is no situation has no permission tier.
    $this->assertTrue($surfaces->access(RecipeSurface::class, new SurfaceContext('configure'), $stranger)->isAllowed());
    $this->assertTrue($surfaces->access(DemoBlockSurface::class, new SurfaceContext('configure'), $stranger)->isNeutral());
  }

  /**
   * Tests that the surface's target is reached through the pipeline.
   */
  public function testTargetThroughThePipeline(): void {
    $context = RecipeSurface::add('main');
    $surface = $this->surfaces()->build(RecipeSurface::class, $context);
    $result = $this->pipeline()->submit($surface, [
      'kitchen' => 'main',
      'name' => 'stew',
      'course' => 'main',
      'dish' => 'risotto',
      'servings' => '4',
      'vegetarian' => '1',
    ], $this->surfaces()->target(RecipeSurface::class, $context));
    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $stored = $this->container->get('state')->get(RecipeTarget::key('main', 'stew'));
    $this->assertSame(4, $stored['servings']);
    $this->assertSame('risotto', $stored['dish']);

    $edit = RecipeSurface::edit('main', 'stew');
    $loaded = $this->surfaces()->target(RecipeSurface::class, $edit)->load($this->surfaces()->build(RecipeSurface::class, $edit));
    $this->assertSame('risotto', $loaded['dish']);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The block.data_surface_demo surface names no target');
    $this->surfaces()->target(DemoBlockSurface::class, new SurfaceContext('configure'));
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
