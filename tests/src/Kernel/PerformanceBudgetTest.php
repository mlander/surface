<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\ResourceCollectorPass;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What a request pays, held to a budget: builds counted, entries sized.
 *
 * Counts and sizes, never wall time, so a regression fails the same way
 * on every machine: deriving the tools builds each operation once, a
 * request building one bundle reads the index and that bundle's entries,
 * and no discovery entry grows past its bound.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PerformanceBudgetTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_examples_field'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Tests that deriving every tool builds each operation once.
   *
   * One build per write tool's operation, shared by its inputs, its read
   * tool and its outputs; and one more for the outputs of each operation
   * that needs nothing and creates, built in the bare context: the two
   * bundles' `create`. Before the derivation memo it was about 1.6 per tool,
   * read tools included.
   */
  public function testDerivingEveryToolBuildsEachOperationOnce(): void {
    $this->resources()->forgetBuilds();
    $before = $this->resources()->buildCounts()['built'];
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $built = $this->resources()->buildCounts()['built'] - $before;
    $writes = array_filter(array_keys($definitions), static fn (string $id): bool => str_starts_with($id, 'surface:') && !str_ends_with($id, ':read') && !str_ends_with($id, ':schema'));
    $this->assertCount(8, $writes);
    $this->assertLessThanOrEqual(count($writes) + 2, $built);
  }

  /**
   * Tests that no discovery entry grows past its bound.
   *
   * The index holds a name, an id, a kind, a module and a base per
   * definition, and the shapes; an entry holds one definition. Bounds a
   * third or more above what the fixture measures, so a definition that
   * starts carrying what it should not (a built surface, a field's whole
   * definition) fails here.
   */
  public function testDiscoveryEntriesStayWithinBounds(): void {
    $this->container->get('surface.resource_registry')->getDefinitions();
    $hash = hash('xxh3', serialize($this->container->getParameter(ResourceCollectorPass::PARAMETER)));
    $bin = $this->container->get('cache.discovery');
    $index = $bin->get('surface:resources:index:' . $hash);
    $this->assertNotFalse($index);
    $names = $index->data['names'];
    $this->assertLessThan(400 * count($names), strlen(serialize($index->data)), 'The index stays under 400 bytes a definition.');
    $bounds = ['class' => 8 * 1024, 'entity.content' => 12 * 1024, 'field.item' => 6 * 1024];
    foreach ($names as $name => $indexed) {
      $entry = $bin->get('surface:resources:entry:' . $hash . ':' . $name);
      $this->assertNotFalse($entry, $name . ' has an entry.');
      $bound = $bounds[$indexed['base'] ?? 'class'];
      $this->assertLessThan($bound, strlen(serialize($entry->data)), sprintf('%s stays under %d bytes.', $name, $bound));
    }
  }

}
