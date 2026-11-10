<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_openapi\Kernel;

use Drupal\surface_openapi\OpenApiDoors;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests surface_openapi with surface_http alone describes the API.
 *
 * With the base module, the two modules and no example module installed,
 * the OpenAPI document describes the paths of a resource of the adopter's
 * own module, and the catalog names its OpenAPI operation. The document
 * is fetched through the HTTP kernel, so the route and the controller are
 * the module's own.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OpenApiDoorWithoutExamplesTest extends SurfaceKernelTestBase {

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
    'surface_openapi',
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
   * Tests the OpenAPI document describes the module's resource.
   */
  public function testTheOpenApiDocumentDescribesTheResource(): void {
    $this->drupalGet('/surface-api/openapi.json');
    $this->assertSession()->statusCodeEquals(200);
    $document = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertArrayHasKey('/surface-api/my_module.settings', $document['paths']);
    $methods = $document['paths']['/surface-api/my_module.settings'];
    $this->assertArrayHasKey('get', $methods);
    $this->assertArrayHasKey('patch', $methods);
    $this->assertArrayHasKey('my_module.settings.configure', $document['components']['schemas']);
  }

  /**
   * Tests the catalog names the OpenAPI operation that runs the update.
   */
  public function testTheCatalogNamesTheOpenApiDoor(): void {
    $doors = $this->container->get('surface.site_catalog')->describe()['my_module.settings']['operations'][0]['doors'];
    $this->assertSame('my_module.settings:configure:write', $doors[OpenApiDoors::DOOR]);
  }

}
