<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\ServedPathsInterface;
use Drupal\surface_openapi\OpenApiDocument;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the post-update that installs surface_http and surface_openapi.
 *
 * A site that installed the module before the HTTP door and the OpenAPI
 * document became modules of their own has neither: the update is
 * pending there and installs both, so the site keeps serving the API and
 * its document. A role that held the documentation pages' permission
 * keeps it and depends on surface_openapi afterwards. A fresh install
 * records the update as run, so a new site is not given either module.
 *
 * @see surface_post_update_install_http_and_openapi()
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class InstallHttpAndOpenApiUpdateTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
  ];

  /**
   * The update this test is about.
   */
  protected const UPDATE = 'surface_post_update_install_http_and_openapi';

  /**
   * The two modules the update installs.
   */
  protected const MODULES = ['surface_http', 'surface_openapi'];

  /**
   * The permission surface_openapi defines.
   */
  protected const PERMISSION = 'access surface api docs';

  /**
   * Enables the base module as code that landed on an existing site does.
   *
   * KernelTestBase enables a module without the module installer, so no
   * post-update is recorded as run: the state of a site whose code was
   * updated and whose updates have not run yet.
   */
  protected function enableAsAnExistingSite(): void {
    $this->enableModules(['surface']);
    $this->installConfig(['system', 'surface']);
    require_once dirname(__DIR__, 3) . '/surface.post_update.php';
  }

  /**
   * Tests the update is pending on an existing site and installs both.
   */
  public function testTheUpdateIsPendingAndInstallsBoth(): void {
    $this->enableAsAnExistingSite();
    // A role granted the permission while the base module defined it: its
    // stored dependency is the base module.
    \Drupal::configFactory()->getEditable('user.role.docs_reader')->setData([
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['module' => ['surface']],
      'id' => 'docs_reader',
      'label' => 'Docs reader',
      'weight' => 3,
      'is_admin' => FALSE,
      'permissions' => [self::PERMISSION],
    ])->save();

    $this->assertContains(self::UPDATE, \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions());
    foreach (self::MODULES as $module) {
      $this->assertFalse(\Drupal::moduleHandler()->moduleExists($module), $module . ' is not installed before the update.');
    }

    $message = (string) (self::UPDATE)();
    $this->assertSame("Installed surface_http, surface_openapi. Export the site's configuration so core.extension lists them.", $message);
    foreach (self::MODULES as $module) {
      $this->assertTrue(\Drupal::moduleHandler()->moduleExists($module), $module . ' is installed by the update.');
    }
    $routes = \Drupal::service('router.route_provider')->getRoutesByNames(['surface.api', 'surface.openapi.json']);
    $this->assertSame(['surface.api', 'surface.openapi.json'], array_keys($routes));
    $this->assertInstanceOf(ServedPathsInterface::class, \Drupal::service('surface.served_paths'));
    $this->assertInstanceOf(OpenApiDocument::class, \Drupal::service('surface.openapi_document'));

    $role = Role::load('docs_reader');
    $this->assertNotNull($role);
    $this->assertTrue($role->hasPermission(self::PERMISSION), 'The role keeps the permission.');
    $this->assertContains('surface_openapi', $role->getDependencies()['module'] ?? [], 'The role depends on the module that defines the permission.');
  }

  /**
   * Tests a site that has surface_http alone gets surface_openapi.
   */
  public function testTheUpdateInstallsTheMissingOne(): void {
    $this->enableAsAnExistingSite();
    \Drupal::service('module_installer')->install(['surface_http']);
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('surface_openapi'));

    $message = (string) (self::UPDATE)();
    $this->assertSame("Installed surface_openapi. Export the site's configuration so core.extension lists them.", $message);
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('surface_http'));
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('surface_openapi'));
  }

  /**
   * Tests a fresh install records the update as run and installs neither.
   */
  public function testFreshInstallRecordsTheUpdateAsRun(): void {
    \Drupal::service('module_installer')->install(['surface']);

    $this->assertNotContains(self::UPDATE, \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions());
    foreach (self::MODULES as $module) {
      $this->assertFalse(\Drupal::moduleHandler()->moduleExists($module), $module . ' is not installed with the base module.');
    }
  }

}
