<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Component\Datetime\Time;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_discovery_test\Plugin\Derivative\RegistryAskingActions;
use Drupal\surface_resource_test\Plugin\Block\StickyNoteBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that discovery answers the same whichever end is asked first.
 *
 * Bug B18 of the reviews (2026-10-20): the plugin hosts are read for the
 * plugins that are their own structure, and an action deriver that asks
 * the registry (eca_tool's, through the tool deriver) asked it while they
 * were being read. The registry was answered with no plugin that is its
 * own structure, memoized that for the request, and wrote it to the
 * discovery bin: 90 definitions on the site instead of 91, the demo
 * formatter missing. The fixture's deriver asks the same way, and the
 * sticky note block is the plugin that is its own structure.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DiscoveryOrderTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_resource_test',
    'surface_discovery_test',
  ];

  /**
   * Starts from a container that has read nothing.
   */
  protected function setUp(): void {
    parent::setUp();
    // The rebuilt container is this test's again: register() keeps it.
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('cache.discovery')->deleteAll();
    RegistryAskingActions::$seen = [];
  }

  /**
   * Tests the plugin hosts read first, the order that lost a definition.
   */
  public function testPluginHostsFirst(): void {
    $plugins = $this->container->get('surface.resource_plugins');
    $this->assertArrayHasKey(StickyNoteBlock::class, $plugins->ownStructures());
    $this->assertTrue($plugins->isComplete());
    $this->assertNotSame([], RegistryAskingActions::$seen, 'The deriver asked the registry while the hosts were being read.');
    $this->assertComplete();
  }

  /**
   * Tests the registry asked first, which reads the hosts itself.
   */
  public function testRegistryFirst(): void {
    $this->assertComplete();
    $this->assertNotSame([], RegistryAskingActions::$seen, 'The deriver asked the registry while the hosts were being read.');
  }

  /**
   * Tests that the plugin hosts asked again, cached, change nothing.
   */
  public function testBothOrdersAgree(): void {
    $first = array_keys($this->container->get('surface.resource_registry')->getDefinitions());
    // The rebuilt container is this test's again: register() keeps it.
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('cache.discovery')->deleteAll();
    $this->container->get('surface.resource_plugins')->ownStructures();
    $this->assertSame($first, array_keys($this->container->get('surface.resource_registry')->getDefinitions()));
  }

  /**
   * Asserts the registry, its cache and every answer inside the cycle.
   *
   * Whole: what a registry discovers fresh once the hosts are read, on a
   * bin of its own. The registry's memo is that; nothing was written to
   * the discovery bin under the id of an answer without the sticky note;
   * and the call made inside the cycle was answered with it too.
   */
  protected function assertComplete(): void {
    $registry = $this->container->get('surface.resource_registry');
    $names = array_keys($registry->getDefinitions());
    $classes = $this->container->getParameter('surface.resource_classes');
    $fresh = new ResourceRegistry(
      $classes,
      new MemoryBackend(new Time()),
      $this->container->get('surface.resource_plugins'),
      $this->container->get('class_resolver'),
    );
    $whole = array_keys($fresh->getDefinitions());
    $this->assertContains(StickyNoteBlock::class, $whole);
    $this->assertSame($whole, $names);
    $prefix = (new \ReflectionClassConstant(ResourceRegistry::class, 'CACHE_PREFIX'))->getValue();
    $this->assertFalse($this->container->get('cache.discovery')->get($prefix . hash('xxh3', serialize($classes))), 'Nothing discovered without the plugins that are their own structure was cached.');
    foreach (RegistryAskingActions::$seen as $seen) {
      $this->assertSame($whole, $seen);
    }
  }

}
