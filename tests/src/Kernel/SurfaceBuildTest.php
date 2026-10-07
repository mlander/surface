<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfaceShape;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;
use Drupal\data_surface_surface_test\Surface\Broken\AttachingSurface;
use Drupal\data_surface_surface_test\Surface\Broken\ClashingSituationSurface;
use Drupal\data_surface_surface_test\Surface\Broken\RefinesOutputSurface;
use Drupal\data_surface_surface_test\Surface\Broken\UndeclaredIdentitySurface;
use Drupal\data_surface_surface_test\Surface\Broken\WatchesMismatchSurface;
use Drupal\data_surface_surface_test\Surface\Broken\WatchesUndeclaredSurface;
use Drupal\data_surface_surface_test\Surface\Broken\WideningRefinerSurface;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;
use Drupal\data_surface_surface_test\Target\RecipeTarget;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\data_surface\Kernel\Fixture\LegacyDemoBlockDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the build step: a surface class and a context in, a surface out.
 *
 * The demo block's surface is held to what the old spelling produced for
 * it, key for key and refinement for refinement. The recipe fixture
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
   * The host class and host id of every build event, in order.
   *
   * @var array<int, array{0: string, 1: string}>
   */
  protected array $builds = [];

  /**
   * Records one build event.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The event.
   */
  public function recordBuild(DataSurfaceBuildEvent $event): void {
    $this->builds[] = [$event->hostClass, $event->hostId];
  }

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
   * Builds the demo block's surface in the old spelling.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface the old declaration produced.
   */
  protected function legacyDemoSurface(): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder(refiner: new LegacyDemoBlockDeclaration(
      $this->container->get('entity_type.bundle.info'),
      $this->container->get('entity_field.manager'),
    ));
    LegacyDemoBlockDeclaration::declareDataSurface($builder);
    return $this->surfaceFactory()->build($builder, LegacyDemoBlockDeclaration::class, 'test:legacy_demo');
  }

  /**
   * Builds the demo block's surface in the new spelling.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface DemoBlockSurface produces.
   */
  protected function demoSurface(): DataSurfaceInterface {
    return $this->surfaces()->build(DemoBlockSurface::class, new SurfaceContext('configure'));
  }

  /**
   * Describes a definition the way two spellings have to agree on it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   Its type, words, flags, constraints, default and examples.
   */
  protected function describe(DataDefinitionInterface $definition): array {
    return [
      'type' => $definition->getDataType(),
      'label' => (string) $definition->getLabel(),
      'description' => (string) $definition->getDescription(),
      'required' => $definition->isRequired(),
      'constraints' => $definition->getConstraints(),
      'has_default' => DefinitionMetadata::hasDefaultValue($definition),
      'default' => DefinitionMetadata::defaultOf($definition),
      'examples' => DefinitionMetadata::getExamples($definition),
    ];
  }

  /**
   * Gets the values a definition offers, labeled, as strings.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array<string, string>|null
   *   The offered labels keyed by value, or NULL when it offers no list.
   */
  protected function offered(DataDefinitionInterface $definition): ?array {
    $set = $this->options()->resolve($definition);
    return $set === NULL ? NULL : array_map('strval', $set->options);
  }

  /**
   * Tests that the new spelling declares what the old one did.
   */
  public function testDemoSurfaceMatchesTheOldSpelling(): void {
    $legacy = $this->legacyDemoSurface();
    $surface = $this->demoSurface();

    $this->assertSame(
      array_keys($legacy->getDefinitions()->toArray()),
      array_keys($surface->getDefinitions()->toArray()),
      'Same keys, in the same order.',
    );
    foreach ($legacy->getDefinitions() as $name => $definition) {
      $this->assertEquals($this->describe($definition), $this->describe($surface->getDefinition($name)), $name);
    }
    $this->assertSame($legacy->getDefaultValues(), $surface->getDefaultValues());
    $this->assertSame($legacy->getDefinitions()->refinements(), $surface->getDefinitions()->refinements());
    $this->assertSame(['bundle' => ['entity_type'], 'field' => ['entity_type', 'bundle']], $surface->getDefinitions()->refinements());
  }

  /**
   * Tests that the two spellings refine to the same offers.
   *
   * The constraint differs — LabeledChoice computed by a refiner holding
   * services in the old spelling, a constraint pointing at a list in the
   * new one — and what a person is offered, what the description says,
   * and what is accepted do not.
   *
   * @param array $values
   *   The sibling values to refine against.
   */
  #[DataProvider('refinementValues')]
  public function testDemoRefinementMatchesTheOldSpelling(array $values): void {
    $legacy = $this->legacyDemoSurface()->refine($values);
    $surface = $this->demoSurface()->refine($values);
    foreach (['bundle', 'field'] as $key) {
      $this->assertSame($this->offered($legacy->getDefinition($key)), $this->offered($surface->getDefinition($key)), $key);
      $this->assertSame(
        (string) $legacy->getDefinition($key)->getDescription(),
        (string) $surface->getDefinition($key)->getDescription(),
        $key,
      );
    }
    foreach ([$values + ['bundle' => 'page', 'field' => 'title'], $values + ['bundle' => 'nope', 'field' => 'nope']] as $candidate) {
      $candidate += $this->demoSurface()->getDefaultValues();
      $this->assertSame(
        $this->pipeline()->validate($this->legacyDemoSurface(), $candidate)->keys(),
        $this->pipeline()->validate($this->demoSurface(), $candidate)->keys(),
      );
    }
  }

  /**
   * Supplies sibling values for the refinement parity.
   *
   * @return array
   *   Cases.
   */
  public static function refinementValues(): array {
    return [
      'nothing chosen' => [[]],
      'node' => [['entity_type' => 'node']],
      'user' => [['entity_type' => 'user']],
      'node article' => [['entity_type' => 'node', 'bundle' => 'article']],
      'user user' => [['entity_type' => 'user', 'bundle' => 'user']],
    ];
  }

  /**
   * Tests that the generated form rebuilds the same in both spellings.
   */
  public function testDemoFormMatchesTheOldSpelling(): void {
    $values = ['entity_type' => 'node', 'bundle' => 'article'] + $this->demoSurface()->getDefaultValues();
    $legacy = $this->formBuilder()->buildSurfaceForm($this->legacyDemoSurface(), $values, new FormState());
    $surface = $this->formBuilder()->buildSurfaceForm($this->demoSurface(), $values, new FormState());
    foreach (['headline', 'entity_type', 'bundle', 'field', 'limit', 'show_summary'] as $key) {
      $this->assertSame($legacy[$key]['#type'], $surface[$key]['#type'], $key);
      $this->assertSame(isset($legacy[$key]['#ajax']), isset($surface[$key]['#ajax']), $key);
      $this->assertSame(
        array_map('strval', $legacy[$key]['#options'] ?? []),
        array_map('strval', $surface[$key]['#options'] ?? []),
        $key,
      );
    }
  }

  /**
   * Tests that the block host builds the surface #[UsesSurface] names.
   *
   * Through the factory, naming the block as the host, so a subscriber
   * written for the block in the old spelling keeps matching it.
   */
  public function testBlockHostBuildsTheUsedSurface(): void {
    $this->container->get('event_dispatcher')->addListener(DataSurfaceBuildEvent::class, [$this, 'recordBuild']);
    $definition = $this->container->get('plugin.manager.block')->getDefinition('data_surface_demo');
    $this->assertSame(DemoBlockSurface::class, $definition[UsesSurface::DEFINITION_KEY]);

    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    $this->assertSame([[DataSurfaceDemoBlock::class, 'block:data_surface_demo']], $this->builds);
    $this->assertSame(
      array_keys($this->demoSurface()->getDefinitions()->toArray()),
      array_keys($block->getDataSurface()->getDefinitions()->toArray()),
    );

    // A surface built for no host is named for itself.
    $this->builds = [];
    $this->demoSurface();
    $this->assertSame([[DemoBlockSurface::class, 'surface:block.data_surface_demo']], $this->builds);
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

    // The refiners ride inside the surface, alter service and all.
    // A cached form restores the surface whole, so this allows every
    // class the way core's own form cache does.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize(serialize($surface));
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
      'subsurfaces wait for step 2' => [
        AttachingSurface::class,
        'attachBy() cannot attach a subsurface at "settings" yet: subsurfaces arrive in step 2 of the rework (REWORK.md).',
      ],
    ];
  }

  /**
   * Tests that attach() is declared and refuses until step 2.
   */
  public function testAttachWaitsForStepTwo(): void {
    $shape = new SurfaceShape(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('attach() cannot attach a subsurface at "storage" yet: subsurfaces arrive in step 2');
    $shape->attach('storage', RecipeSurface::class);
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
