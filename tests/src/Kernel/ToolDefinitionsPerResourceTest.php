<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each resource's tools are derived once and kept under its own tags.
 *
 * The Tool API keeps every tool as one item, which any tag a tool was read
 * from clears; deriving again reads each resource's tools from its own
 * entry in the discovery bin, and derives only those whose entry went,
 * inside core's lock, each operation built once for all its tools.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ToolDefinitionsPerResourceTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::ENTITY_MODULES;

  /**
   * The lock the deriver is handed, which records what it was asked.
   */
  protected RecordingLock $lock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->lock = new RecordingLock();
    $this->container->set('lock', $this->lock);
  }

  /**
   * Gets the build step, whose counts say what was built.
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
   * Lists the tools, as a new request would after what it is handed.
   *
   * @return array<string, int>
   *   How many builds of each resource listing the tools made.
   */
  protected function listInNewRequest(): array {
    // A later request has built nothing yet.
    $this->resources()->forgetBuilds();
    $before = $this->resources()->buildCounts()['resources'];
    $this->container->get('plugin.manager.tool')->getDefinitions();
    $made = [];
    foreach ($this->resources()->buildCounts()['resources'] as $name => $count) {
      if ($count > ($before[$name] ?? 0)) {
        $made[$name] = $count - ($before[$name] ?? 0);
      }
    }
    return $made;
  }

  /**
   * Tests that one field saved derives that bundle's tools and no other.
   */
  public function testOneFieldSavedDerivesOneResourceAgain(): void {
    $cold = $this->listInNewRequest();
    $this->assertArrayHasKey('entity.content:node.page', $cold);
    $this->assertArrayHasKey('entity.content:node.kitchen', $cold);
    // Each operation of a bundle (add, and edit with its read tool) is
    // built once for every tool it makes, and once more for its outputs.
    $this->assertLessThanOrEqual(4, $cold['entity.content:node.page']);

    $field = FieldConfig::loadByName('node', 'page', 'field_description');
    assert($field !== NULL);
    $field->set('label', 'Summary')->save();
    $this->assertSame(['entity.content:node.page' => $cold['entity.content:node.page']], $this->listInNewRequest());
    $listing = $this->container->get('plugin.manager.tool')->getDefinition('surface:entity.content:node.page:create');
    $this->assertArrayHasKey('field_description', $listing->getInputDefinition('values')->getPropertyDefinitions());

    // Nothing saved, nothing derived: every resource reads its entry.
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $this->assertSame([], $this->listInNewRequest());
  }

  /**
   * Tests that deriving what was not cached takes the lock, and nothing else.
   */
  public function testTheLockIsTakenForRebuildOnly(): void {
    $this->listInNewRequest();
    $this->assertSame([
      ['acquire', ResourceOperationToolDeriver::LOCK],
      ['release', ResourceOperationToolDeriver::LOCK],
    ], $this->lock->asked);

    $this->lock->asked = [];
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $this->listInNewRequest();
    $this->assertSame([], $this->lock->asked, 'Reading every entry takes no lock.');
  }

  /**
   * Tests that a request finding the lock taken waits, then reads.
   */
  public function testBurstWaitsForOneRebuild(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $this->listInNewRequest();
    $base = clone $tools->getDefinition('surface:entity.content:node.page:create');
    // A deploy: the bin emptied, every request after it cold.
    $this->container->get('cache.discovery')->deleteAll();
    $tools->clearCachedDefinitions();
    $this->lock->asked = [];
    $this->lock->taken = TRUE;
    // While this request waits, another derives and writes every entry.
    $this->lock->whileWaiting = function () use ($base): void {
      $this->lock->whileWaiting = NULL;
      $this->lock->taken = FALSE;
      ResourceOperationToolDeriver::create($this->container, 'surface')->getDerivativeDefinitions($base);
    };
    $tools->getDefinitions();
    $this->assertSame([
      ['acquire', ResourceOperationToolDeriver::LOCK],
      ['wait', ResourceOperationToolDeriver::LOCK],
      // The other request's rebuild, under the lock.
      ['acquire', ResourceOperationToolDeriver::LOCK],
      ['release', ResourceOperationToolDeriver::LOCK],
    ], $this->lock->asked, 'After the wait this request read what the other wrote, and derived nothing.');
    $this->assertArrayHasKey('surface:entity.content:node.page:update', $tools->getDefinitions());
    $this->assertArrayHasKey('surface:entity.content:node.kitchen:update:read', $tools->getDefinitions());
  }

}
