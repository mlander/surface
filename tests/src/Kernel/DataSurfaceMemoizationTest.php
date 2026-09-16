<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a configurable host builds its surface once.
 *
 * The three methods ConfigurableInterface asks for all need the surface,
 * and a host calls them freely: one block submit used to ask three
 * times, which dispatched the build event three times, so three sets of
 * subscribers ran over three separate builders for what is supposed to
 * be one advertisement. Anything that reads live site state — a bundle
 * list, a plugin list, the current user — could have answered
 * differently on each pass.
 *
 * The event is counted rather than the surfaces compared, because the
 * event is the expensive and the observable part: it is where other
 * modules get to contribute, and firing it repeatedly is what makes the
 * advertisement unstable.
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
   * The host ids the build event was dispatched for.
   *
   * @var string[]
   */
  protected array $builds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('event_dispatcher')->addListener(
      DataSurfaceBuildEvent::class,
      function (DataSurfaceBuildEvent $event): void {
        $this->builds[] = $event->hostId;
      },
    );
  }

  /**
   * Tests that one instance builds one surface however often it is asked.
   */
  public function testOneInstanceBuildsOneSurface(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block', ['headline' => 'First']);
    $this->assertInstanceOf(DataSurfaceTestBlock::class, $block);
    // Creating the block already asked: BlockPluginTrait's constructor
    // calls setConfiguration() before any subclass body has run.
    $this->assertSame(['block:data_surface_test_block'], $this->builds);

    $block->defaultConfiguration();
    $block->getConfiguration();
    $block->setConfiguration(['headline' => 'Second']);
    $block->getConfiguration();

    $this->assertSame(['block:data_surface_test_block'], $this->builds);
    $this->assertSame('Second', $block->getConfiguration()['headline']);
  }

  /**
   * Tests that a second instance is a second advertisement.
   *
   * The memo is per instance and nothing invalidates it, which is only
   * safe because a surface is a per-request description: the request
   * that needs a different one is the next request, with a new instance.
   */
  public function testEachInstanceBuildsItsOwn(): void {
    $manager = $this->container->get('plugin.manager.block');
    $manager->createInstance('data_surface_test_block', []);
    $manager->createInstance('data_surface_test_block', []);

    $this->assertCount(2, $this->builds);
  }

}
