<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the demo block can be constructed on a site without node.
 *
 * A declared default has to satisfy the key's own constraints, and for
 * this plugin type it has to do so at construction: BlockPluginTrait's
 * constructor applies the default configuration before any subclass body
 * runs, and the base class applies it through the pipeline. A default of
 * 'node' therefore made the block impossible to instantiate on a site
 * without the node module — the block's own PluginExists constraint
 * refused its own declared default — and the failure surfaced as a fatal
 * from the plugin manager rather than as a validation message anybody
 * could act on.
 *
 * Every other test of this block installs node, so none of them could
 * see it. This one exists to install as little as the block needs and no
 * more, which is the only configuration in which the bug is visible.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DemoBlockWithoutNodeTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block',
    'data_surface',
    'data_surface_demo',
  ];

  /**
   * Tests that the block instantiates and declares a usable default.
   */
  public function testBlockIsConstructibleWithoutNode(): void {
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('node'),
      'The bug this test covers is only visible without the node module.',
    );

    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_demo');
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);

    // The declared default is a content entity type this site has, so
    // the configuration the constructor applied is configuration the
    // surface itself accepts — which is the property a default has to
    // have on a plugin type that applies its defaults at construction.
    $surface = $block->getDataSurface();
    $defaults = $surface->getDefaultValues();
    $this->assertSame('user', $defaults['entity_type']);
    $this->assertCount(0, $this->pipeline()->validate($surface, $defaults));
    $this->assertSame('user', $block->getConfiguration()['entity_type']);
  }

  /**
   * Tests that the entity type select offers only what this site has.
   */
  public function testTheSelectOffersOnlyInstalledEntityTypes(): void {
    $definition = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_demo')
      ->getDataSurface()
      ->getDefinition('entity_type');

    $options = $this->options()->resolve($definition)->options;

    // The constraint names a manager and an interface, and the resolver
    // reads it live, so the list is this site's content entity types
    // rather than a list written down beside the declaration.
    $this->assertArrayHasKey('user', $options);
    $this->assertArrayNotHasKey('node', $options);
  }

}
