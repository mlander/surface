<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A route served by an operation asks the operation, without saying so.
 *
 * Every route whose defaults name a resource and an operation gets the
 * requirement `_data_surface_operation_access`, so the shipped routing
 * files leave it out; the resource test module's larder route still says
 * it, and is read the same.
 *
 * @see \Drupal\data_surface\Access\OperationRouteSubscriber
 * @see docs/decisions.md#the-surface-was-counted-and-cut
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class OperationRouteAccessTest extends DataSurfaceKernelTestBase {

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
    'data_surface_resource_test',
    'data_surface_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface', 'data_surface_first']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests every operation route has the check, said or not, and it gates.
   */
  public function testEveryOperationRouteAsksTheOperation(): void {
    $checked = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if (!$route->hasDefault(OperationRoute::RESOURCE) || !$route->hasDefault(OperationRoute::OPERATION)) {
        continue;
      }
      $this->assertSame('TRUE', $route->getRequirement(OperationRoute::ACCESS), $name);
      $this->assertContains('data_surface.operation_access', $route->getOption('_access_checks') ?? [], $name);
      $checked[] = $name;
    }
    $this->assertContains('data_surface_first.newsletter', $checked, 'A route that does not say it has it.');
    $this->assertContains('data_surface_resource_test.larder.update', $checked, 'A route that says it keeps it.');

    $access = $this->container->get('access_manager');
    $this->assertFalse($access->checkNamedRoute('data_surface_first.newsletter', [], new AnonymousUserSession()), 'The operation\'s permission is asked.');
    $this->setUpCurrentUser([], ['administer data_surface_first']);
    $this->assertTrue($access->checkNamedRoute('data_surface_first.newsletter', [], $this->container->get('current_user')));
  }

  /**
   * Tests the post-update moves reference_enum_limit to its new name.
   *
   * Renamed beside site_list_limit; the value a site set is kept.
   */
  public function testThePostUpdateRenamesTheReferenceLimit(): void {
    $config = $this->config('data_surface.settings');
    $this->assertSame(50, $config->get('reference_list_limit'));
    $raw = $config->getRawData();
    unset($raw['reference_list_limit']);
    $this->container->get('config.storage')->write('data_surface.settings', ['reference_enum_limit' => 7] + $raw);
    $this->container->get('config.factory')->reset('data_surface.settings');
    require_once dirname(__DIR__, 3) . '/data_surface.post_update.php';
    data_surface_post_update_reference_list_limit();
    $settings = $this->config('data_surface.settings');
    $this->assertSame(7, $settings->get('reference_list_limit'));
    $this->assertNull($settings->get('reference_enum_limit'));
    $this->assertSame(['reference_list_limit', 'site_list_limit'], array_values(array_filter(array_keys($settings->getRawData()), static fn (string $key): bool => !str_starts_with($key, '_'))));
  }

}
