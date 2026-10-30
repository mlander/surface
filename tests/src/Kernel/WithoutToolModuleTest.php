<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\ContractPanel;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\SiteCatalog;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the module on a site without the tool module.
 *
 * The module does not depend on the tool module. Its tool plugins are
 * found only by the tool module's plugin manager, the services of the
 * Tool API door are registered only when the tool module is installed
 * (SurfaceServiceProvider), and the contract panel takes the tool
 * manager and the serializer as optional arguments. So without the tool
 * module the container builds, a resource builds and its form serves
 * with the contract panel beside it, the panel shows the contract and the
 * full schema and no tool definition, the catalog names no tool door, and
 * no class of the door is loaded.
 *
 * The route test module's welcome card serves the form: a route that
 * names the contract panel.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WithoutToolModuleTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_route_test',
  ];

  /**
   * The services of the Tool API door, registered only with the tool module.
   */
  protected const TOOL_SERVICES = [
    'surface.input_definitions',
    'surface.operation_inputs',
    'surface.tool_definitions_invalidator',
    'surface.tool_doors',
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
   * Tests the container builds with none of the door's services.
   */
  public function testTheContainerBuildsWithoutTheDoor(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('tool'));
    $this->assertFalse($this->container->has('plugin.manager.tool'));
    foreach (self::TOOL_SERVICES as $id) {
      $this->assertFalse($this->container->has($id), $id);
    }
    $this->assertInstanceOf(ContractPanel::class, $this->container->get('surface.contract_panel'));
    $this->assertNoToolClassLoaded();
  }

  /**
   * Tests a resource builds and its form serves, the panel beside it.
   */
  public function testTheFormServesWithThePanel(): void {
    $resources = $this->container->get('surface.resources');
    $surface = $resources->build('route_test.card', $resources->operation('route_test.card', 'create'));
    $this->assertTrue($surface->getDefinitions()->has('title'));

    $this->serveRoute('surface_route_test.add_welcome');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('item', $container['title']['#type']);
    $this->assertSame('Welcome', $container['title']['#value']);

    $panel = $container[SurfaceOperationForm::PANEL_KEY];
    $this->assertNotEmpty($panel['keys']['#rows']);
    $this->assertStringContainsString('"const": "Welcome"', $panel['contract']['json']['#code']);
    $this->assertSame('The full schema, for any answers', (string) $panel['full']['#title']);
    $this->assertArrayNotHasKey('tool', $panel);
    $this->assertNoToolClassLoaded();
  }

  /**
   * Tests the panel, asked directly, shows the contract and no tool.
   */
  public function testThePanelShowsTheContractAlone(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('route_test.card', 'create');
    $served = new OperationRoute(
      'route_test.card',
      $this->container->get('surface.resource_registry')->getOperation('route_test.card', 'create'),
      $context,
      NULL,
    );
    $panel = $this->container->get('surface.contract_panel')
      ->buildPanel($served, $resources->build('route_test.card', $context), []);

    $contract = json_decode((string) $panel['contract']['json']['#code'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('title', $contract['properties']);
    $full = json_decode((string) $panel['full']['json']['#code'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('title', $full['properties']);
    $this->assertSame(['intro', 'keys', 'contract', 'full'], array_values(array_filter(array_keys($panel), static fn (string $key): bool => !str_starts_with($key, '#'))));
    $this->assertNoToolClassLoaded();
  }

  /**
   * Tests the catalog names the form door and no tool door.
   */
  public function testTheCatalogListsNoToolDoor(): void {
    $doors = [];
    foreach ($this->container->get('surface.site_catalog')->describe() as $entry) {
      foreach ($entry['operations'] ?? [] as $operation) {
        $doors += array_fill_keys(array_keys($operation['doors'] ?? []), TRUE);
      }
    }
    $this->assertArrayHasKey(SiteCatalog::FORM, $doors);
    $this->assertArrayNotHasKey(SiteCatalog::TOOL, $doors);
    $this->assertArrayNotHasKey(SiteCatalog::READ_TOOL, $doors);
    $this->assertArrayNotHasKey(SiteCatalog::SCHEMA_TOOL, $doors);
    $this->assertNoToolClassLoaded();
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
   * Asserts no class of the Tool API door was loaded.
   *
   * The tool plugins and their deriver, and the door's classes under
   * src/Tool: each names the Tool API's classes, which this site does not
   * have, so loading one would fail or would mean something reached for
   * the door.
   */
  protected function assertNoToolClassLoaded(): void {
    $root = dirname(__DIR__, 3) . '/src';
    $files = [
      ...glob($root . '/Plugin/tool/Tool/*.php'),
      ...glob($root . '/Plugin/Derivative/*.php'),
      ...glob($root . '/Tool/*.php'),
    ];
    $this->assertNotEmpty(glob($root . '/Plugin/tool/Tool/*.php'));
    foreach ($files as $file) {
      $class = 'Drupal\\surface\\' . str_replace('/', '\\', substr($file, strlen($root) + 1, -4));
      $loaded = class_exists($class, FALSE) || trait_exists($class, FALSE) || interface_exists($class, FALSE);
      $this->assertFalse($loaded, $class . ' was loaded.');
    }
  }

}
