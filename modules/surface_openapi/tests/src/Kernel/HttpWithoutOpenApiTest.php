<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_openapi\Kernel;

use Drupal\surface_http\HttpDoors;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests surface_http without surface_openapi serves the API and no document.
 *
 * The OpenAPI document is a module of its own over the HTTP door: with
 * surface_http alone, a resource is read and its full schema fetched at
 * `/surface-api/{resource}`, while `/surface-api/openapi.json` is a path
 * the API reads as a resource it does not have, a 404 as problem details,
 * and the document's pages are not there. The catalog names the HTTP door
 * and no OpenAPI door.
 *
 * Decision: see
 * docs/decisions.md#the-http-door-and-the-openapi-document-are-their-own-modules.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class HttpWithoutOpenApiTest extends SurfaceKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'surface', 'my_module']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the API serves a resource and the document's path is not found.
   */
  public function testTheApiServesAndTheDocumentIsNotFound(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('surface_openapi'));
    $this->assertFalse($this->container->has('surface.openapi_document'));

    $this->drupalGet('/surface-api/my_module.settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('my_module.settings', $this->json()['resource']);
    $this->drupalGet('/surface-api/my_module.settings/schema');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/surface-api/openapi.json');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertStringStartsWith('application/problem+json', (string) $this->getSession()->getResponseHeader('Content-Type'));
    $routes = [
      'surface.openapi.json',
      'surface.openapi.yaml',
      'surface.openapi.docs',
      'surface.openapi.reference',
    ];
    $this->assertSame([], $this->container->get('router.route_provider')->getRoutesByNames($routes));
  }

  /**
   * Tests the catalog names the HTTP door and no OpenAPI door.
   */
  public function testTheCatalogNamesNoOpenApiDoor(): void {
    $doors = $this->container->get('surface.site_catalog')->describe()['my_module.settings']['operations'][0]['doors'];
    // OpenApiDoors::DOOR, written out: the class is not on this site.
    $this->assertSame('PATCH /surface-api/my_module.settings', $doors[HttpDoors::DOOR]);
    $this->assertArrayNotHasKey('openapi', $doors);
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

}
