<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceCollectorPass;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Structure\DemoBlockStructure;
use Drupal\data_surface_demo_extras\Alter\DemoBlockAlter;
use Drupal\data_surface_resource_test\Access\RecipeAccess;
use Drupal\data_surface_resource_test\OutsideDiscovery\BothKindsClass;
use Drupal\data_surface_resource_test\Shape\Sku;
use Drupal\data_surface_resource_test\OutsideDiscovery\DefaultOperationStructure;
use Drupal\data_surface_resource_test\OutsideDiscovery\HomelessResource;
use Drupal\data_surface_resource_test\OutsideDiscovery\OperationAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\OperationOfAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\OutputsAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\RecipeByIdAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\SkuByIdAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\UnknownIdAlter;
use Drupal\data_surface_resource_test\OutsideDiscovery\WatchesRefinerStructure;
use Drupal\data_surface_resource_test\OutsideDiscovery\InstanceDefineInputsStructure;
use Drupal\data_surface_resource_test\OutsideDiscovery\WrongKindStructure;
use Drupal\data_surface_resource_test\Plugin\Block\StickyNoteBlock;
use Drupal\data_surface_resource_test\Structure\HerbGarnishStructure;
use Drupal\data_surface_resource_test\Resource\RecipeResource;
use Drupal\data_surface_resource_test\Alter\EditOnlyRecipeAlter;
use Drupal\data_surface_resource_test\Alter\RecipeAlter;
use Drupal\data_surface_resource_test\Storage\RecipeStorage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests discovery of resources, structures and alters, and the demo alter.
 *
 * Nothing in the modules below registers a resource, a structure, an
 * alter or an operation by hand: the compiler pass finds them all, the
 * way core finds hook classes.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceDiscoveryTest extends DataSurfaceKernelTestBase {

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
    'data_surface_demo_extras',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Gets the registry.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceRegistry
   *   The registry.
   */
  protected function registry(): ResourceRegistry {
    return $this->container->get('data_surface.resource_registry');
  }

  /**
   * Tests that the demo structure and its alter are found.
   */
  public function testDemoStructureIsDiscovered(): void {
    $definition = $this->registry()->getDefinition(DemoBlockStructure::class);
    $this->assertSame('block.data_surface_demo', $definition->id);
    $this->assertSame('data_surface_demo', $definition->module);
    $this->assertSame($definition, $this->registry()->getDefinition('block.data_surface_demo'));
    $this->assertSame([], $this->registry()->getOperations(DemoBlockStructure::class));
    $this->assertSame([], $definition->identity);
    $this->assertNull($definition->storage);

    $alters = $this->registry()->getAlters(DemoBlockStructure::class);
    $this->assertCount(1, $alters);
    $this->assertSame(DemoBlockAlter::class, $alters[0]->class);
    $this->assertSame('data_surface_demo_extras', $alters[0]->module);
    $this->assertSame(['limit'], array_map(static fn ($refiner) => $refiner->key, $alters[0]->refiners));

    // An alter is an autowired service, the way a hook class is.
    $this->assertInstanceOf(DemoBlockAlter::class, $this->container->get(DemoBlockAlter::class));
  }

  /**
   * Tests operations, alters, variants and named classes of the fixture.
   */
  public function testRecipeIsDiscovered(): void {
    $definition = $this->registry()->getDefinition(RecipeResource::class);
    $this->assertSame(['kitchen', 'name'], $definition->identity);
    $this->assertSame(RecipeStorage::class, $definition->storage);
    $this->assertSame(RecipeAccess::class, $definition->access);
    $this->assertSame(['dish', 'servings', 'name'], array_map(static fn ($refiner) => $refiner->key, $definition->refiners));
    $this->assertSame(['course', 'vegetarian'], $definition->refiners[1]->watched());
    $this->assertSame(['course'], $definition->refiners[0]->watched());

    // Its operations stand on its own class.
    $operations = $this->registry()->getOperations(RecipeResource::class);
    $this->assertSame(['create', 'update', 'mislabeled'], array_keys($operations));
    $this->assertSame('cook in %kitchen', $operations['create']->permission);
    $this->assertSame('Add a recipe', (string) $operations['create']->label);

    $this->assertSame(
      [EditOnlyRecipeAlter::class, RecipeAlter::class],
      array_map(static fn ($alter) => $alter->class, $this->registry()->getAlters(RecipeResource::class)),
    );
    $this->assertSame(['update'], $this->registry()->getAlters(RecipeResource::class)[0]->operations);

    // The variant is found at the key it names, for its value.
    $this->assertSame(['herb' => HerbGarnishStructure::class], $this->registry()->getVariants(RecipeResource::class, 'garnish_settings'));

    // Storage and access classes are autowired services too.
    $this->assertInstanceOf(RecipeStorage::class, $this->container->get(RecipeStorage::class));
    $this->assertInstanceOf(RecipeAccess::class, $this->container->get(RecipeAccess::class));
  }

  /**
   * Tests that what the registry read is cached in the discovery bin.
   */
  public function testDefinitionsAreCached(): void {
    $this->registry()->getDefinitions();
    $classes = $this->container->getParameter(ResourceCollectorPass::PARAMETER);
    $plugins = $this->container->get('data_surface.resource_plugins');
    // The ids carry both lists: the classes the compiler pass found and
    // the plugins that are their own structure.
    $hash = hash('xxh3', serialize([$classes, $plugins->ownStructures()]));
    $bin = $this->container->get('cache.discovery');
    $index = $bin->get('data_surface:resources:index:' . $hash);
    $this->assertNotFalse($index);
    // The index names every resource and structure, and holds the shapes
    // by class; each definition is an entry of its own.
    $this->assertArrayHasKey(DemoBlockStructure::class, $index->data['names']);
    $this->assertArrayHasKey(StickyNoteBlock::class, $index->data['names']);
    $this->assertSame('resource_test.sku', $index->data['shapes'][Sku::class]->id);
    $entry = $bin->get('data_surface:resources:entry:' . $hash . ':' . DemoBlockStructure::class);
    $this->assertNotFalse($entry);
    $this->assertSame(DemoBlockStructure::class, $entry->data->class);
    // A class read from code alone carries no tag: a cache rebuild is what
    // reads it again.
    $this->assertSame([], $entry->tags);

    // A second registry reads the cache rather than the classes.
    $fresh = new ResourceRegistry($classes, $this->container->get('cache.discovery'), $plugins);
    $this->assertEquals($this->registry()->getDefinitions(), $fresh->getDefinitions());
  }

  /**
   * Tests that a structure whose defineInputs() is not static is refused.
   *
   * PHP refuses a class implementing DefinesInputsInterface with an instance
   * defineInputs() before discovery could, so the class that reaches
   * discovery with one has left the interface off; it is named for what
   * it is rather than only for the missing interface.
   */
  public function testAnInstanceDefineInputsIsRefused(): void {
    $registry = new ResourceRegistry(
      ['resources' => [InstanceDefineInputsStructure::class => 'data_surface_resource_test']],
      $this->container->get('cache.discovery'),
    );
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(InstanceDefineInputsStructure::class . ' carries #[Structure] and declares defineInputs() as an instance method. What a class declares belongs to the class, and the method takes nothing but the blueprint to fill: declare it `public static function defineInputs(BlueprintInterface $inputs): void`, and implement Drupal\data_surface\Resource\StructureInterface.');
    $registry->getDefinitions();
  }

  /**
   * Tests that a class is one kind, and carries the attribute of it.
   *
   * Both attributes on one class are refused, and so is the attribute of
   * one kind on a class implementing the other kind's interface.
   */
  public function testClassIsOneKind(): void {
    foreach ([
      BothKindsClass::class => BothKindsClass::class . ' carries both #[Resource] and #[Structure].',
      WrongKindStructure::class => WrongKindStructure::class . ' carries #[Structure] but does not implement Drupal\data_surface\Resource\StructureInterface.',
    ] as $class => $message) {
      $registry = new ResourceRegistry(['resources' => [$class => 'data_surface_resource_test']], $this->container->get('cache.discovery'));
      try {
        $registry->getDefinitions();
        $this->fail($class . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringStartsWith($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests a removed attribute argument is refused, naming what replaced it.
   *
   * Removed on 2026-10-24: #[RefinesInput(watches:)], #[Operation(default:)]
   * and #[Operation(of:)]; an operation on a class that is no resource or
   * structure is refused too.
   */
  public function testRemovedArgumentsAreRefused(): void {
    $module = 'data_surface_resource_test';
    $recipe = [RecipeResource::class => $module];
    $refusals = [
      [
        ['resources' => [WatchesRefinerStructure::class => $module]],
        "#[RefinesInput('third')] on " . WatchesRefinerStructure::class . '::thirdOf() says watches:, which the attribute no longer takes: what a refiner watches is its parameters after the definition, each named for the key it is handed.',
      ],
      [
        [
          'resources' => [DefaultOperationStructure::class => $module],
          'operations' => [DefaultOperationStructure::class => $module],
        ],
        "#[Operation('first')] on " . DefaultOperationStructure::class . "::first() says default:, which the attribute no longer takes: a child is invoked through the operation its parent's context names, else its operation with the parent context's id, else its operation with the standard id of the parent operation's kind (create, update or configure, read or list, delete).",
      ],
      [
        ['resources' => $recipe, 'operations' => [OperationOfAlter::class => $module]],
        "#[Operation('clone')] on " . OperationOfAlter::class . "::clone() says of:, which the attribute no longer takes: an operation stands on its resource's or structure's own class.",
      ],
      [
        ['resources' => $recipe, 'operations' => [OperationAlter::class => $module]],
        "#[Operation('taste')] on " . OperationAlter::class . "::taste() stands on a class that is no resource or structure. An operation stands on its resource's or structure's own class.",
      ],
    ];
    foreach ($refusals as [$classes, $message]) {
      try {
        (new ResourceRegistry($classes, $this->container->get('cache.discovery')))->getDefinitions();
        $this->fail($message);
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests an alter that adds outputs is refused, saying what replaced it.
   *
   * Removed on 2026-10-24 with AltersOutputsInterface: one that still
   * implements it cannot be loaded, and is refused by name where the
   * scanned directories are read; one that keeps alterOutputs() is
   * refused where the alters are read.
   */
  public function testAnAlterAddingOutputsIsRefused(): void {
    $tail = " adds outputs (Drupal\\data_surface\\Resource\\AltersOutputsInterface, alterOutputs()), which an alter no longer may since 2026-10-24: what a call answers is its operation's outputs, declared by the resource or structure that owns it, and a reaction to a save belongs to ECA.";
    try {
      ResourceCollectorPass::reflect('Drupal\\data_surface_resource_test\\OutsideDiscovery\\OutputsInterfaceAlter');
      $this->fail('An alter implementing the removed interface was read.');
    }
    catch (\LogicException $e) {
      $this->assertSame('Drupal\\data_surface_resource_test\\OutsideDiscovery\\OutputsInterfaceAlter' . $tail, $e->getMessage());
    }
    $registry = new ResourceRegistry([
      'resources' => [RecipeResource::class => 'data_surface_resource_test'],
      'alters' => [OutputsAlter::class => 'data_surface_resource_test'],
    ], $this->container->get('cache.discovery'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(OutputsAlter::class . $tail);
    $registry->getDefinitions();
  }

  /**
   * Tests that an alter naming an owner with a class by its id is refused.
   *
   * The id spelling is for a derived resource, which has no class, and a
   * deriver's base: ContentEntityAccessTest mounts an alter by the base id
   * `entity.content`. An id nothing claims is skipped, as a class that does
   * not load is.
   */
  public function testAnAlterNamingAnOwnerByItsIdIsRefused(): void {
    $cases = [
      RecipeByIdAlter::class => ['resource_test.recipe', RecipeResource::class],
      SkuByIdAlter::class => ['resource_test.sku', Sku::class],
    ];
    foreach ($cases as $alter => [$id, $owner]) {
      $registry = new ResourceRegistry([
        'resources' => [RecipeResource::class => 'data_surface_resource_test'],
        'shapes' => [Sku::class => 'data_surface_resource_test'],
        'alters' => [$alter => 'data_surface_resource_test'],
      ], $this->container->get('cache.discovery'));
      try {
        $registry->getDefinitions();
        $this->fail(sprintf('%s was not refused.', $alter));
      }
      catch (\LogicException $e) {
        $this->assertSame(sprintf("#[Alters('%s')] on %s names an id; name the owner by its class, %s::class. An id names only a derived resource, which has no class.", $id, $alter, $owner), $e->getMessage());
      }
    }

    $registry = new ResourceRegistry([
      'resources' => [RecipeResource::class => 'data_surface_resource_test'],
      'alters' => [UnknownIdAlter::class => 'data_surface_resource_test'],
    ], $this->container->get('cache.discovery'));
    $this->assertSame([], $registry->getAlters(RecipeResource::class));
  }

  /**
   * Tests that a resource with no storage, identity or host is refused.
   */
  public function testResourceHasStorageOrHost(): void {
    $registry = new ResourceRegistry(
      ['resources' => [HomelessResource::class => 'data_surface_resource_test']],
      $this->container->get('cache.discovery'),
    );
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(HomelessResource::class . ' carries #[Resource] and names no storage, no identity and no plugin that hosts it');
    $registry->getDefinitions();
  }

  /**
   * Tests that the extras alter applies to the demo block.
   */
  public function testExtrasAlterApplies(): void {
    $surface = $this->container->get('data_surface.resources')->build(DemoBlockStructure::class, new OperationContext('configure'));

    // The badge is mounted under the alter's module, with its default.
    $this->assertSame(
      ['data_surface_demo_extras' => ['badge' => 'star']],
      $surface->getDefaultValues()['third_party_settings'],
    );

    // The limit is refined against the presentation, after the owner.
    $this->assertSame(['limit' => ['presentation']], array_intersect_key($surface->getDefinitions()->refinements(), ['limit' => TRUE]));
    $range = static fn (array $values): array => $surface->refine($values)->getDefinition('limit')->getConstraints()['Range'];
    $this->assertSame(['min' => 1, 'max' => DemoBlockAlter::GRID_LIMIT], $range(['presentation' => 'grid']));
    $this->assertSame(['min' => 1, 'max' => 50], $range(['presentation' => 'list']));

    // And it rewords the headline, the one change to an owner's key an
    // alter may make: its help text, not its width.
    $headline = $surface->getDefinition('headline');
    $this->assertSame('Shown above the featured content, beside its badge.', (string) $headline->getDescription());
    $this->assertSame('Headline', (string) $headline->getLabel());
    $this->assertSame(['max' => 50], $headline->getConstraints()['Length']);

    // And the block, built by its host, carries both and stores both.
    $this->container->get('theme_installer')->install(['stark']);
    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    $this->assertSame('star', $block->getConfiguration()['third_party_settings']['data_surface_demo_extras']['badge']);
    $this->assertContains('limit', $this->pipeline()->validate(
      $block->getDataSurface(),
      ['limit' => 30, 'presentation' => 'grid', 'presentation_settings' => ['columns' => 3]] + $block->getConfiguration(),
    )->keys());

    // The schema the extras module ships describes the mount, which the
    // strict schema check on save holds it to.
    $placed = Block::create([
      'id' => 'demo',
      'theme' => 'stark',
      'plugin' => 'data_surface_demo',
      'settings' => ['label' => 'Demo'] + $block->getConfiguration(),
    ]);
    $placed->save();
    $this->assertSame('star', $placed->getPlugin()->getConfiguration()['third_party_settings']['data_surface_demo_extras']['badge']);
  }

}
