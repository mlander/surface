<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\FormRouteDoors;
use Drupal\surface_examples_react\ReactDoors;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the React example on a site that has not installed surface_http.
 *
 * The state of a site updated to the code that moved the HTTP door into
 * surface_http, before the update that installs it has run: the React
 * example is installed and depends on a module that is not. The container
 * still builds, so the update can be reached. The catalog lists only the
 * React pages modules serve at routes of their own, the example's own
 * page is refused, and a module's route the page serves answers 404 with
 * a reason instead of failing on a missing service.
 *
 * Decision: see
 * docs/decisions.md#the-http-door-and-the-openapi-document-are-their-own-modules.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReactWithoutHttpModuleTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * This test is about a module installed without its dependency.
   *
   * The React example depends on surface_http, which a site updated to
   * this code has not installed until its update runs.
   */
  public const MODULES_WITHOUT_DEPENDENCIES = TRUE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_examples_react',
    'surface_route_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the container builds with the React doors and no HTTP door.
   */
  public function testTheContainerBuilds(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('surface_http'));
    $this->assertFalse($this->container->has('surface.http_doors'));
    $this->assertFalse($this->container->has('surface.served_operations'));
    $this->assertInstanceOf(ReactDoors::class, $this->container->get('surface_examples_react.react_doors'));
  }

  /**
   * Tests the catalog lists the module-route React pages alone.
   */
  public function testTheCatalogListsOnlyModuleRoutePages(): void {
    $doors = [];
    foreach ($this->container->get('surface.site_catalog')->describe()['route_test.card']['operations'] as $operation) {
      $doors[$operation['id']] = $operation['doors'];
    }
    $this->assertSame('/surface-test/card/react/{copies} (surface_route_test.react_add) [fills title: Dear you, copies: {copies}; starts occasion from ?occasion, envelope.stamps from ?stamps, greeting.value from ?greeting]', $doors['create'][ReactDoors::DOOR]);
    $this->assertArrayHasKey(FormRouteDoors::DOOR, $doors['create']);
    // The update has no React page a module serves, and no page of its
    // own without the HTTP door.
    $this->assertArrayNotHasKey(ReactDoors::DOOR, $doors['update']);
    $this->assertStringNotContainsString('/surface-react/', (string) json_encode($doors, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Tests the example's own page is refused.
   */
  public function testTheOwnPageIsRefused(): void {
    $access = $this->container->get('access_manager')->checkNamedRoute('surface_examples_react.page', [
      'resource' => 'route_test.card',
      'operation' => 'create',
    ], NULL, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests a module's route the page serves answers 404 with a reason.
   */
  public function testModuleRouteAnswersNotFound(): void {
    $access = $this->container->get('access_manager')->checkNamedRoute('surface_route_test.react_add', ['copies' => 4], NULL, TRUE);
    $this->assertTrue($access->isAllowed(), 'The route is allowed, so the controller is asked.');

    $controller = $this->container->get('router.route_provider')->getRouteByName('surface_route_test.react_add')->getDefault('_controller');
    try {
      $this->container->get('controller_resolver')->getControllerFromDefinition($controller);
      $this->fail('The controller was built without the HTTP door.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertStringContainsString('surface_http', $e->getMessage());
    }
  }

}
