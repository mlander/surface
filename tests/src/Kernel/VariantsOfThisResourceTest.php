<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface_tool_test\Resource\CabinetResource;
use Drupal\data_surface_tool_test\Resource\VaultResource;
use Drupal\data_surface_tool_test\Storage\CabinetStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Access builds a resource only when one of its own variants may refuse it.
 *
 * The cabinet's code lock answers access, so asking about the cabinet
 * builds it to find the lock chosen; the vault holds no such variant, and
 * asking about it builds nothing, though the cabinet is on the same site.
 *
 * Decision: see docs/decisions.md#a-chosen-variant-may-refuse.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class VariantsOfThisResourceTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('state')->set(CabinetStorage::KEY, [
      'label' => 'Cabinet',
      'lock' => 'code',
      'lock_settings' => ['digits' => 4],
    ]);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('data_surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Asks access for one operation and counts the builds it made.
   *
   * @param class-string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   *
   * @return int
   *   The builds made.
   */
  protected function buildsForAccess(string $resource, string $operation): int {
    $resources = $this->resources();
    $resources->forgetBuilds();
    $before = $resources->buildCounts()['built'];
    $resources->access($resource, $resources->operation($resource, $operation));
    return $resources->buildCounts()['built'] - $before;
  }

  /**
   * Tests that only the resource holding the refusing variant is built.
   */
  public function testOnlyTheHoldersAccessBuilds(): void {
    $this->assertSame([CabinetResource::class => TRUE], $this->container->get('data_surface.resource_registry')->getRefusingVariantHolders());
    $this->assertSame(1, $this->buildsForAccess(CabinetResource::class, 'update'));
    $this->assertSame(0, $this->buildsForAccess(VaultResource::class, 'configure'));
  }

}
