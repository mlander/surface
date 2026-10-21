<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock;
use Drupal\data_surface_test\Structure\TestBlockStructure;
use Drupal\Tests\data_surface\Kernel\Fixture\CountingResources;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a configurable host builds its structure once.
 *
 * The three methods ConfigurableInterface asks for all need the structure,
 * and a host calls them freely: one block submit used to ask three
 * times, which ran the build step three times, with every alter, for
 * what is supposed to be one advertisement. Anything that reads live
 * site state — a bundle list, a plugin list, the current user — could
 * have answered differently on each pass.
 *
 * The builds are counted rather than the structures compared, because the
 * build is the expensive and the observable part: it is where alters
 * run, and running it repeatedly is what makes the advertisement
 * unstable.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceMemoizationTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * The build step, counting.
   */
  protected CountingResources $resources;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resources = new CountingResources($this->container->get('data_surface.resources'));
    $this->container->set('data_surface.resources', $this->resources);
  }

  /**
   * Tests that one instance builds one structure however often it is asked.
   */
  public function testOneInstanceBuildsOneStructure(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block', ['headline' => 'First']);
    $this->assertInstanceOf(DataSurfaceTestBlock::class, $block);
    // Creating the block already asked: BlockPluginTrait's constructor
    // calls setConfiguration() before any subclass body has run.
    $this->assertSame([TestBlockStructure::class], $this->resources->builds);

    $block->defaultConfiguration();
    $block->getConfiguration();
    $block->setConfiguration(['headline' => 'Second']);
    $block->getConfiguration();

    $this->assertSame([TestBlockStructure::class], $this->resources->builds);
    $this->assertSame('Second', $block->getConfiguration()['headline']);
  }

  /**
   * Tests that a second instance is a second advertisement.
   *
   * The memo is per instance and nothing invalidates it, which is only
   * safe because a built structure is a per-request description: the request
   * that needs a different one is the next request, with a new instance.
   */
  public function testEachInstanceBuildsItsOwn(): void {
    $manager = $this->container->get('plugin.manager.block');
    $manager->createInstance('data_surface_test_block', []);
    $manager->createInstance('data_surface_test_block', []);

    $this->assertCount(2, $this->resources->builds);
  }

}
