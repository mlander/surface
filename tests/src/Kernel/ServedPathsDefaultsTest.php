<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface_paths_test\Resource\BoardResource;
use Drupal\surface_paths_test\Resource\CrateResource;
use Drupal\surface_paths_test\Resource\DeskResource;
use Drupal\surface_paths_test\Resource\PanelResource;
use Drupal\surface_paths_test\Resource\ShelfResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which operation is the default of each kind at each path.
 *
 * The default of a kind is the operation whose id is the kind's standard
 * one (`create`, `update`, `read` or `list`, `delete`), or `configure`
 * for an update on a singleton; else the only operation of the kind
 * there; else none, and every operation of the kind is a custom method.
 *
 * @see docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ServedPathsDefaultsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_paths_test',
  ];

  /**
   * Tests each standard id, and `configure`, makes its kind's default.
   */
  public function testTheStandardIdsAreTheDefaults(): void {
    $this->assertSame([
      0 => [
        OperationDefinition::CREATE => 'create',
        OperationDefinition::UPDATE => NULL,
        OperationDefinition::READ => 'list',
        OperationDefinition::DELETE => NULL,
      ],
      1 => [
        OperationDefinition::CREATE => NULL,
        OperationDefinition::UPDATE => NULL,
        OperationDefinition::READ => 'read',
        OperationDefinition::DELETE => 'delete',
      ],
    ], $this->defaults(ShelfResource::class), 'create beside stock, list, read, and delete beside clear: each the standard id.');
    $this->assertSame('update', $this->defaults(BoardResource::class)[0][OperationDefinition::UPDATE], 'update beside touch, on a singleton.');
    $this->assertSame('configure', $this->defaults(PanelResource::class)[0][OperationDefinition::UPDATE], 'configure beside touch, on a singleton.');
  }

  /**
   * Tests where no id or two ids name the default, there is none.
   */
  public function testNoDefaultWithoutOneName(): void {
    $this->assertNull($this->defaults(DeskResource::class)[0][OperationDefinition::UPDATE], 'Both update and configure on one singleton: neither.');
    $this->assertNull($this->defaults(CrateResource::class)[1][OperationDefinition::UPDATE], 'configure off a singleton names no default, so two updates leave none.');
    $this->assertSame(['update', 'configure'], OperationDefinition::defaultIds(OperationDefinition::UPDATE, TRUE), 'configure names an update on a singleton.');
    $this->assertSame(['update'], OperationDefinition::defaultIds(OperationDefinition::UPDATE, FALSE));
    $this->assertSame(['read', 'list'], OperationDefinition::defaultIds(OperationDefinition::READ, TRUE));
  }

  /**
   * Gets the defaults of a resource, by depth and kind.
   *
   * @param class-string $class
   *   The resource.
   *
   * @return array<int, array<string, string|null>>
   *   ServedPaths::defaults().
   */
  protected function defaults(string $class): array {
    $definition = $this->container->get('surface.resource_registry')->getDefinition($class);
    return $this->container->get('surface.served_paths')->defaults($definition);
  }

}
