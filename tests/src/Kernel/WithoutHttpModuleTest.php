<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\ContractPanel;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\FormRouteDoors;
use Drupal\surface\ResourceBuild\ServedPathsInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ToolDoors;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the module on a site without surface_http and surface_openapi.
 *
 * The HTTP door and the OpenAPI document are modules of their own. The
 * main module names a served path only through ServedPathsInterface,
 * which surface_http provides. So with neither installed the container
 * builds, the form door serves an operation, the catalog lists no HTTP
 * or OpenAPI door, the contract panel names no path the API serves, the
 * derived tools name no `/surface-api` URL, and no class of either
 * module is loaded.
 *
 * Decision: see
 * docs/decisions.md#the-http-door-and-the-openapi-document-are-their-own-modules.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WithoutHttpModuleTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
    'surface_route_test',
  ];

  /**
   * The two modules this site does not have.
   */
  protected const MODULES = ['surface_http', 'surface_openapi'];

  /**
   * The services the two modules register.
   */
  protected const SERVICES = [
    'surface.served_operations',
    'surface.served_paths',
    'surface.served_access',
    'surface.problem_details',
    'surface.schema_tag',
    'surface.http_doors',
    'surface.openapi_document',
    'surface.openapi_doors',
    ServedPathsInterface::class,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface', 'surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the container builds with none of the two modules' services.
   */
  public function testTheContainerBuildsWithoutTheDoors(): void {
    foreach (self::MODULES as $module) {
      $this->assertFalse($this->container->get('module_handler')->moduleExists($module), $module);
    }
    foreach (self::SERVICES as $id) {
      $this->assertFalse($this->container->has($id), $id);
    }
    $this->assertInstanceOf(ContractPanel::class, $this->container->get('surface.contract_panel'));
    $this->assertInstanceOf(OperationInputs::class, $this->container->get('surface.operation_inputs'));
    $routes = ['surface.api', 'surface.openapi.json', 'surface.openapi.docs'];
    $this->assertSame([], $this->container->get('router.route_provider')->getRoutesByNames($routes));
    $this->assertNoDoorClassLoaded();
  }

  /**
   * Tests the form door serves an operation, its panel naming no path.
   */
  public function testTheFormServesAndThePanelNamesNoPath(): void {
    $this->serveRoute('surface_route_test.add_welcome');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('item', $container['title']['#type']);
    $this->assertSame('Welcome', $container['title']['#value']);

    $panel = $form[SurfaceOperationForm::PANEL_KEY];
    $this->assertStringContainsString('"const": "Welcome"', $panel['contract']['json']['#code']);
    $note = (string) $panel['contract']['note']['#markup'];
    $this->assertStringContainsString('so this describes these answers and no others.</p>', $note);
    $this->assertStringNotContainsString('/surface-api', $note);
    $this->assertStringNotContainsString('HTTP door', $note);
    $this->assertNoDoorClassLoaded();
  }

  /**
   * Tests the catalog names the form and tool doors, and neither of these.
   */
  public function testTheCatalogListsNoHttpOrOpenApiDoor(): void {
    $doors = [];
    foreach ($this->container->get('surface.site_catalog')->describe() as $entry) {
      foreach ($entry['operations'] ?? [] as $operation) {
        $doors += array_fill_keys(array_keys($operation['doors'] ?? []), TRUE);
      }
    }
    $this->assertArrayHasKey(FormRouteDoors::DOOR, $doors);
    $this->assertArrayHasKey(ToolDoors::TOOL, $doors);
    // HttpDoors::DOOR and OpenApiDoors::DOOR, written out: neither class
    // is on this site.
    $this->assertArrayNotHasKey('http', $doors);
    $this->assertArrayNotHasKey('openapi', $doors);
    $this->assertNoDoorClassLoaded();
  }

  /**
   * Tests no derived tool names a URL of the API.
   *
   * The poster's layouts and the workshop's benches past the site's
   * limit, so each write tool states a key open and has a narrowing tool:
   * where the API is served, both name the full schema's fetch, and the
   * narrowing tool's schema output names its path. Here they name only
   * the narrowing tool.
   */
  public function testTheToolsNameNoServedPath(): void {
    $layouts = [];
    $benches = [];
    for ($n = 1; $n <= 25; $n++) {
      $layouts['extra_' . $n] = [
        'label' => 'Extra ' . $n,
        'schema' => [
          'type' => 'object',
          'properties' => ['caption_' . $n => ['type' => 'string', 'title' => 'Caption']],
          'additionalProperties' => FALSE,
        ],
      ];
      $benches['bench_' . $n] = ['label' => 'Bench ' . $n];
    }
    $this->container->get('state')->set(PosterLayouts::STATE, $layouts);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $this->config(WorkshopBenches::CONFIG)->set('benches', $benches)->save();

    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    $serializer = $this->container->get('tool.definition_serializer');
    $narrowing = 0;
    foreach ($manager->getDefinitions() as $id => $definition) {
      if (!str_starts_with($id, 'surface:')) {
        continue;
      }
      $tool = $manager->createInstance($id);
      $said = json_encode([
        (string) $definition->getDescription(),
        $serializer->normalizeInputSchema($tool, 'json_schema', TRUE),
        $serializer->normalizeOutputSchema($tool),
      ], JSON_UNESCAPED_SLASHES);
      $this->assertStringNotContainsString('/surface-api', (string) $said, $id);
      $narrowing += (int) str_ends_with($id, ':schema');
    }
    $this->assertGreaterThanOrEqual(2, $narrowing, 'The poster and the workshop have a narrowing tool.');

    $poster = $manager->getDefinition('surface:resource_test.poster:configure:schema');
    $this->assertStringContainsString('the write tool does not list them in full: send your values here', (string) $poster->getDescription());
    $panels = $manager->getDefinition('surface:resource_test.poster:configure')->getInputDefinition('values')->getPropertyDefinitions()['panels'];
    $this->assertStringContainsString('call surface:resource_test.poster:configure:schema with your values', (string) $panels->getDescription());
    $this->assertNoDoorClassLoaded();
  }

  /**
   * Puts a route of the route test module on the request stack.
   *
   * @param string $name
   *   The route's name.
   */
  protected function serveRoute(string $name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Asserts no class of surface_http or surface_openapi was loaded.
   */
  protected function assertNoDoorClassLoaded(): void {
    foreach (self::MODULES as $module) {
      $root = dirname(__DIR__, 3) . '/modules/' . $module . '/src';
      $files = [...glob($root . '/*.php'), ...glob($root . '/*/*.php')];
      $this->assertNotEmpty($files, $module);
      foreach ($files as $file) {
        $class = 'Drupal\\' . $module . '\\' . str_replace('/', '\\', substr($file, strlen($root) + 1, -4));
        $this->assertFalse(class_exists($class, FALSE), $class . ' was loaded.');
      }
    }
  }

}
