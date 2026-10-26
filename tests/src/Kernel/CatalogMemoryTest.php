<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Drush\Commands\CatalogCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The catalog of a placed block answers within a bounded memory.
 *
 * `drush surface:catalog --resource=block.placement` walks every key the
 * build seals, a variant's keys under each value. A placed block's
 * settings are a variant per block plugin, the component block's props
 * and slots a variant per component, and each slot a component again: one
 * walk per place a structure is used grows with the product of the lists,
 * which exhausted 3 GB on a site with 312 components. Variants the site
 * lists past the site list limit are listed by value and not walked, so
 * the walk is bounded by the limit at every depth.
 *
 * @see docs/decisions.md#a-budget-for-what-the-site-decides
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class CatalogMemoryTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_component_block',
    'data_surface_block',
  ];

  /**
   * The most a catalog of the placed block may add to the peak, in bytes.
   */
  protected const BUDGET = 64 * 1024 * 1024;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'data_surface']);
    $this->container->get('theme_installer')->install(['stark']);
  }

  /**
   * Runs the command for the placed block, as JSON.
   *
   * @return array{0: array, 1: int}
   *   What it listed, decoded, and what it added to the peak memory.
   */
  protected function placement(): array {
    // Built once first, so the measure is the walk and its rendering.
    $this->container->get('data_surface.site_catalog')->refused();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $tester = new CommandTester(new CatalogCommand($this->container->get('data_surface.site_catalog')));
    $status = $tester->execute(['--resource' => 'block.placement', '--format' => 'json']);
    $peak = memory_get_peak_usage() - $before;
    $this->assertSame(0, $status, $tester->getDisplay());
    return [json_decode($tester->getDisplay(), TRUE, 512, JSON_THROW_ON_ERROR), $peak];
  }

  /**
   * Tests the walk stops at variants past the limit, within the budget.
   */
  public function testThePlacedBlockAnswersWithinTheBudget(): void {
    // On the test site every list is under the limit: every key is walked.
    [$entry, $peak] = $this->placement();
    $keys = array_column($entry['keys'], NULL, 'key');
    $this->assertTrue($keys['settings']['variants']['listed']);
    $this->assertArrayHasKey('settings.props', $keys);
    $walked = count($entry['keys']);
    $this->assertLessThan(self::BUDGET, $peak, sprintf('The catalog of the placed block added %d MB to the peak.', intdiv($peak, 1048576)));

    // Past the limit (two here, for the four components and the block
    // plugins), the settings are listed by value and not walked.
    $this->container->get('data_surface.site_list_budget')->withLimit(2, function () use ($walked): void {
      [$entry, $peak] = $this->placement();
      $keys = array_column($entry['keys'], NULL, 'key');
      $this->assertFalse($keys['settings']['variants']['listed']);
      $this->assertContains('data_surface_component', array_keys($keys['settings']['variants']['values']));
      $this->assertSame([], array_filter(array_keys($keys), static fn (string $key): bool => str_starts_with($key, 'settings.')));
      $this->assertLessThan($walked, count($entry['keys']));
      $this->assertLessThan(self::BUDGET, $peak);
      [, $table] = $this->table();
      $this->assertStringContainsString('variants, more than the site list limit: their keys are not listed here', $table);
    });
  }

  /**
   * Runs the command for the placed block, as a table.
   *
   * @return array{0: int, 1: string}
   *   The exit status and what it printed.
   */
  protected function table(): array {
    $tester = new CommandTester(new CatalogCommand($this->container->get('data_surface.site_catalog')));
    $status = $tester->execute(['--resource' => 'block.placement']);
    return [$status, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
  }

}
