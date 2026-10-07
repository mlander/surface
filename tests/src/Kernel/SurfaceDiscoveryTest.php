<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfaceCollectorPass;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;
use Drupal\data_surface_demo_extras\SurfaceAlter\DemoBlockAlter;
use Drupal\data_surface_surface_test\Access\RecipeAccess;
use Drupal\data_surface_surface_test\Surface\HerbGarnishSurface;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;
use Drupal\data_surface_surface_test\SurfaceAlter\EditOnlyRecipeAlter;
use Drupal\data_surface_surface_test\SurfaceAlter\RecipeAlter;
use Drupal\data_surface_surface_test\Target\RecipeTarget;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests discovery of src/Surface and src/SurfaceAlter, and the demo alter.
 *
 * Nothing in the modules below registers a surface, an alter or a
 * situation by hand: the compiler pass finds them all, the way core finds
 * hook classes.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceDiscoveryTest extends DataSurfaceKernelTestBase {

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
  }

  /**
   * Gets the registry.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfaceRegistry
   *   The registry.
   */
  protected function registry(): SurfaceRegistry {
    return $this->container->get('data_surface.surface_registry');
  }

  /**
   * Tests that the demo surface and its alter are found.
   */
  public function testDemoSurfaceIsDiscovered(): void {
    $definition = $this->registry()->getDefinition(DemoBlockSurface::class);
    $this->assertSame('block.data_surface_demo', $definition->id);
    $this->assertSame('data_surface_demo', $definition->module);
    $this->assertSame($definition, $this->registry()->getDefinition('block.data_surface_demo'));
    $this->assertSame([], $this->registry()->getSituations(DemoBlockSurface::class));
    $this->assertSame([], $definition->identity);
    $this->assertNull($definition->target);

    $alters = $this->registry()->getAlters(DemoBlockSurface::class);
    $this->assertCount(1, $alters);
    $this->assertSame(DemoBlockAlter::class, $alters[0]->class);
    $this->assertSame('data_surface_demo_extras', $alters[0]->module);
    $this->assertSame(['limit'], array_map(static fn ($refiner) => $refiner->key, $alters[0]->refiners));

    // An alter is an autowired service, the way a hook class is.
    $this->assertInstanceOf(DemoBlockAlter::class, $this->container->get(DemoBlockAlter::class));
  }

  /**
   * Tests situations, alters, variants and named classes of the fixture.
   */
  public function testRecipeIsDiscovered(): void {
    $definition = $this->registry()->getDefinition(RecipeSurface::class);
    $this->assertSame(['kitchen', 'name'], $definition->identity);
    $this->assertSame(RecipeTarget::class, $definition->target);
    $this->assertSame(RecipeAccess::class, $definition->access);
    $this->assertSame(['dish', 'servings', 'name'], array_map(static fn ($refiner) => $refiner->key, $definition->refiners));
    $this->assertSame(['course', 'vegetarian'], $definition->refiners[1]->watched());
    $this->assertSame(['course'], $definition->refiners[0]->watched());

    // A situation from another class names its surface with `of`.
    $situations = $this->registry()->getSituations(RecipeSurface::class);
    $this->assertSame(['add', 'edit', 'clone', 'mislabeled'], array_keys($situations));
    $this->assertSame('cook in %kitchen', $situations['clone']->permission);
    $this->assertSame('Clone a recipe', (string) $situations['clone']->label);

    $this->assertSame(
      [EditOnlyRecipeAlter::class, RecipeAlter::class],
      array_map(static fn ($alter) => $alter->class, $this->registry()->getAlters(RecipeSurface::class)),
    );
    $this->assertSame(['edit'], $this->registry()->getAlters(RecipeSurface::class)[0]->situations);

    // Collected now, used in step 2.
    $this->assertSame(['herb' => HerbGarnishSurface::class], $this->registry()->getVariants(RecipeSurface::class, 'garnish_settings'));

    // Targets and access classes are autowired services too.
    $this->assertInstanceOf(RecipeTarget::class, $this->container->get(RecipeTarget::class));
    $this->assertInstanceOf(RecipeAccess::class, $this->container->get(RecipeAccess::class));
  }

  /**
   * Tests that what the registry read is cached in the discovery bin.
   */
  public function testDefinitionsAreCached(): void {
    $this->registry()->getDefinitions();
    $classes = $this->container->getParameter(SurfaceCollectorPass::PARAMETER);
    $cached = $this->container->get('cache.discovery')->get('data_surface:surfaces:' . hash('xxh3', serialize($classes)));
    $this->assertNotFalse($cached);
    $this->assertArrayHasKey(DemoBlockSurface::class, $cached->data);

    // A second registry reads the cache rather than the classes.
    $fresh = new SurfaceRegistry($classes, $this->container->get('cache.discovery'));
    $this->assertEquals($this->registry()->getDefinitions(), $fresh->getDefinitions());
  }

  /**
   * Tests that the extras alter applies to the demo block.
   */
  public function testExtrasAlterApplies(): void {
    $surface = $this->container->get('data_surface.surfaces')->build(DemoBlockSurface::class, new SurfaceContext('configure'));

    // The badge is mounted under the alter's module, with its default.
    $this->assertSame(
      ['data_surface_demo_extras' => ['badge' => 'star']],
      $surface->getDefaultValues()['third_party_settings'],
    );

    // The limit is refined against show_summary, after the owner.
    $this->assertSame(['limit' => ['show_summary']], array_intersect_key($surface->getDefinitions()->refinements(), ['limit' => TRUE]));
    $range = static fn (array $values): array => $surface->refine($values)->getDefinition('limit')->getConstraints()['Range'];
    $this->assertSame(['min' => 1, 'max' => DemoBlockAlter::SUMMARY_LIMIT], $range(['show_summary' => TRUE]));
    $this->assertSame(['min' => 1, 'max' => 50], $range(['show_summary' => FALSE]));

    // And the block, built by its host, carries both and stores both.
    $this->container->get('theme_installer')->install(['stark']);
    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    $this->assertSame('star', $block->getConfiguration()['third_party_settings']['data_surface_demo_extras']['badge']);
    $this->assertContains('limit', $this->pipeline()->validate(
      $block->getDataSurface(),
      ['limit' => 30] + $block->getConfiguration(),
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
