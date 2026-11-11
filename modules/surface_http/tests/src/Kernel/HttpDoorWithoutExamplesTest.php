<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_http\Kernel;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface_http\Controller\ResourceApiController;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\surface_http\HttpDoors;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;

/**
 * Tests surface_http alone opens the API.
 *
 * With the base module, surface_http and no example module installed, a
 * resource of the adopter's own module is read and its full schema
 * fetched at `/surface-api/{resource}`, and the catalog names the HTTP
 * door. The OpenAPI document is surface_openapi's, tested there
 * (OpenApiDoorWithoutExamplesTest). The React page
 * is an example client, surface_examples_react: without it there is no
 * page route, the catalog names no React door, and no class of it is
 * loaded.
 *
 * Each request goes through the HTTP kernel, so the routes, the access
 * check and the controller are the module's own.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class HttpDoorWithoutExamplesTest extends SurfaceKernelTestBase {

  use HttpKernelUiHelperTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_http',
    'my_module',
  ];

  /**
   * The example module that serves the React page.
   */
  protected const REACT = 'surface_examples_react';

  /**
   * The React door's name in the catalog (ReactDoors::DOOR).
   *
   * Written out, so no class of the example is loaded.
   */
  protected const REACT_DOOR = 'react';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'surface', 'my_module']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the API reads a resource of the module and serves its schema.
   */
  public function testTheApiServesTheResource(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists(self::REACT));

    $this->drupalGet('/surface-api/my_module.settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    $envelope = $this->json();
    $this->assertSame('my_module.settings', $envelope['resource']);
    $this->assertSame('configure', $envelope['operation']);
    $this->assertStringEndsWith('/surface-api/my_module.settings', $envelope['schema']['$id']);
    $this->assertArrayHasKey('values', $envelope);

    $this->drupalGet('/surface-api/my_module.settings/schema');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringStartsWith(ResourceApiController::SCHEMA_TYPE, (string) $this->getSession()->getResponseHeader('Content-Type'));
    $this->assertStringEndsWith('/surface-api/my_module.settings/schema', $this->json()['$id']);

    // An account without the operation's permission is refused, as
    // problem details.
    $this->setUpCurrentUser();
    $this->drupalGet('/surface-api/my_module.settings');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertStringStartsWith('application/problem+json', (string) $this->getSession()->getResponseHeader('Content-Type'));
    $this->assertNoReactClassLoaded();
  }

  /**
   * Tests the catalog names the HTTP door and no React page.
   */
  public function testTheCatalogNamesNoReactDoor(): void {
    $catalog = $this->container->get('surface.site_catalog');
    $doors = $catalog->describe()['my_module.settings']['operations'][0]['doors'];
    $this->assertSame('PATCH /surface-api/my_module.settings', $doors[HttpDoors::DOOR]);
    $this->assertArrayNotHasKey(self::REACT_DOOR, $doors);
    $this->assertArrayNotHasKey(self::REACT_DOOR, $catalog->links('my_module.settings', 'configure'));
    $this->assertSame([], $this->container->get('router.route_provider')->getRoutesByNames([self::REACT . '.page']));
    $this->assertFalse($this->container->has(self::REACT . '.react_doors'));
    $this->assertNoReactClassLoaded();
  }

  /**
   * Decodes the last response's body.
   *
   * @return array
   *   The JSON document.
   */
  protected function json(): array {
    return json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Asserts no class of the React example was loaded.
   */
  protected function assertNoReactClassLoaded(): void {
    $root = dirname(__DIR__, 5) . '/modules/' . self::REACT . '/src';
    $files = [...glob($root . '/*.php'), ...glob($root . '/*/*.php')];
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $class = 'Drupal\\' . self::REACT . '\\' . str_replace('/', '\\', substr($file, strlen($root) + 1, -4));
      $this->assertFalse(class_exists($class, FALSE), $class . ' was loaded.');
    }
  }

}
