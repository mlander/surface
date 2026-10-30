<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Http\ResourceApiController;
use Drupal\surface\ResourceBuild\SiteCatalog;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the base module alone opens the HTTP door and its OpenAPI document.
 *
 * The HTTP door and the OpenAPI document that describes it are the base
 * module's: with no example module installed, a resource of the
 * adopter's own module is read and its full schema fetched at
 * `/surface-api/{resource}`, the OpenAPI document describes its paths, and
 * the catalog names the HTTP and OpenAPI doors. The React page is an
 * example client, surface_examples_react: without it there is no page
 * route, the catalog names no React door, and no class of it is loaded.
 *
 * Each request goes through the HTTP kernel, so the routes, the access
 * check and the controller are the base module's own.
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
    'my_module',
  ];

  /**
   * The example module that serves the React page.
   */
  protected const REACT = 'surface_examples_react';

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
   * Tests the OpenAPI document describes the module's resource.
   */
  public function testTheOpenApiDocumentDescribesTheResource(): void {
    $this->drupalGet('/surface-api/openapi.json');
    $this->assertSession()->statusCodeEquals(200);
    $document = $this->json();
    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertArrayHasKey('/surface-api/my_module.settings', $document['paths']);
    $methods = $document['paths']['/surface-api/my_module.settings'];
    $this->assertArrayHasKey('get', $methods);
    $this->assertArrayHasKey('patch', $methods);
    $this->assertArrayHasKey('my_module.settings.configure', $document['components']['schemas']);
    $this->assertNoReactClassLoaded();
  }

  /**
   * Tests the catalog names the HTTP and OpenAPI doors and no React page.
   */
  public function testTheCatalogNamesNoReactDoor(): void {
    $catalog = $this->container->get('surface.site_catalog');
    $doors = $catalog->describe()['my_module.settings']['operations'][0]['doors'];
    $this->assertSame('PATCH /surface-api/my_module.settings', $doors[SiteCatalog::HTTP]);
    $this->assertSame('my_module.settings:configure:write', $doors[SiteCatalog::OPENAPI]);
    $this->assertArrayNotHasKey(SiteCatalog::REACT, $doors);
    $this->assertArrayNotHasKey(SiteCatalog::REACT, $catalog->links('my_module.settings', 'configure'));
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
    $root = dirname(__DIR__, 3) . '/modules/' . self::REACT . '/src';
    $files = [...glob($root . '/*.php'), ...glob($root . '/*/*.php')];
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $class = 'Drupal\\' . self::REACT . '\\' . str_replace('/', '\\', substr($file, strlen($root) + 1, -4));
      $this->assertFalse(class_exists($class, FALSE), $class . ' was loaded.');
    }
  }

}
