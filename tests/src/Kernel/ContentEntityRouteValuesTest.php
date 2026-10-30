<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\ResourceBuild\NamesNothingException;
use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface\ResourceBuild\SiteCatalog;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A basic page's add page with its title fixed and its body filled.
 *
 * The generic `create` of `entity.content:node.page`, served at a route
 * that fixes `title.value` and fills `body.value`: each a key of a
 * field's structure, attached at the field's name and invoked through
 * the field's own create, so each reaches the field's context.
 *
 * And one route for every bundle, the bundle in the path and in the
 * resource's id it fills: a bundle no resource is derived for, or a node
 * of another bundle on its edit page, names nothing there.
 *
 * @see docs/forms.md#a-route-that-fixes-or-fills-keys
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityRouteValuesTest extends DataSurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OperationRouteTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'data_surface_route_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'create kitchen content',
      'administer nodes',
      'use text format basic',
    ]);
    $name = 'data_surface_route_test.add_welcome_page';
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Asserts the route on the stack names nothing, and its form is not found.
   *
   * @param string $message
   *   Why.
   * @param string $parameter
   *   The parameter whose value names nothing.
   */
  protected function assertNamesNothing(string $message, string $parameter): void {
    try {
      $this->served();
      $this->fail('The route was served.');
    }
    catch (NamesNothingException $e) {
      $this->assertSame($message, $e->getMessage());
      $this->assertSame($parameter, $e->parameter);
    }
    try {
      $this->surfaceForm();
      $this->fail('The form was built.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * The title is drawn fixed, the body starts filled, both stated and saved.
   */
  public function testTheTitleIsFixedAndTheBodyFilled(): void {
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('item', $form['title']['value']['#type']);
    $this->assertSame('Welcome', $form['title']['value']['#value']);
    $this->assertSame('Start here.', $form['body']['value']['#default_value']);

    // The contract panel beside the form states what the form draws, in
    // the field's structure: the fixed key `const`, its label under
    // `x-surface.fixed`, and the filled key's default.
    $panel = $form[DataSurfaceOperationForm::PANEL_KEY];
    foreach (['contract', 'full'] as $document) {
      $json = $panel[$document]['json']['#code'];
      $this->assertStringContainsString('"const": "Welcome"', $json, $document);
      $this->assertStringContainsString('"fixed": "Welcome"', $json, $document);
      $this->assertStringContainsString('"default": "Start here."', $json, $document);
    }

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => [
        'field_description' => ['value' => 'Described.'],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertCount(1, $ids);
    $node = $storage->load(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('Welcome', $node->label());
    $this->assertSame('Start here.', $node->get('body')->value);
  }

  /**
   * One route serves every bundle a resource is derived for, by its path.
   */
  public function testOneRouteServesEveryBundle(): void {
    foreach (['page' => 'field_description', 'kitchen' => 'field_string'] as $bundle => $field) {
      $this->visit('/surface-test/node/' . $bundle . '/add');
      $served = $this->served();
      $this->assertSame('entity.content:node.' . $bundle, $served->resource);
      // The deriver's create, which knows the bundle from its binding.
      $this->assertSame(['entity_type' => 'node', 'bundle' => $bundle, 'id' => NULL], $served->context->known);
      $this->assertArrayHasKey($field, $this->surfaceForm());
    }

    // A bundle with no form display has no resource: nothing is there.
    $this->visit('/surface-test/node/article/add');
    $this->assertNamesNothing('The data_surface_route_test.add_node route serves "entity.content:node.article" at this path, and no resource has that id.', 'node_type');

    // Core's entity title callback reads two plain defaults the operation
    // does not, and neither is refused.
    $this->visit('/surface-test/node/kitchen/add-titled');
    $this->assertSame('entity.content:node.kitchen', $this->served()->resource);
  }

  /**
   * The catalog lists one route serving every bundle under each, filled.
   */
  public function testTheCatalogListsTheRouteUnderEveryBundle(): void {
    $registry = $this->container->get('data_surface.resource_registry');
    $forms = $this->container->get('data_surface.form_route_doors');
    $door = static function (string $bundle, string $operation) use ($registry, $forms): array {
      $resource = $registry->getDefinition('entity.content:node.' . $bundle);
      $definition = $registry->getOperation($resource->name(), $operation);
      return [
        $forms->doors($resource, $definition, TRUE, FALSE)[SiteCatalog::FORM] ?? '',
        $forms->links($resource, $definition, TRUE, FALSE)[SiteCatalog::FORM] ?? NULL,
      ];
    };
    foreach (['page', 'kitchen'] as $bundle) {
      [$listed] = $door($bundle, 'create');
      // The path filled for the bundle, and what the route says beside.
      $this->assertStringContainsString('/surface-test/node/' . $bundle . '/add (data_surface_route_test.add_node)', $listed);
      $this->assertStringContainsString('/surface-test/node/' . $bundle . '/welcome (data_surface_route_test.add_node_welcome) [fixes title.value: Welcome]', $listed);
      // The two plain defaults core's title callback reads pin no
      // parameter of the operation.
      $this->assertStringContainsString('/surface-test/node/' . $bundle . '/add-titled (data_surface_route_test.add_node_titled)', $listed);
      $this->assertStringNotContainsString('(data_surface_route_test.add_node_titled) [', $listed);
      // The edit page, whose node the URL names: listed.
      [$edit] = $door($bundle, 'update');
      $this->assertStringContainsString('/surface-test/node/' . $bundle . '/{id}/edit (data_surface_route_test.edit_node)', $edit);
    }
    // The page's own add pages name it as written, and come first.
    [$listed, $link] = $door('page', 'create');
    $this->assertStringContainsString('(data_surface_route_test.add_welcome_page)', $listed);
    $this->assertLessThan(strpos($listed, 'add_node)'), strpos($listed, 'add_welcome_page)'));
    $this->assertContains($link?->getRouteName(), [
      'data_surface_entity.node_page_create',
      'data_surface_route_test.add_welcome_page',
    ]);
    // The kitchen has none: its link is a route serving every bundle,
    // filled for the kitchen.
    [, $link] = $door('kitchen', 'create');
    $this->assertSame(['node_type' => 'kitchen'], $link?->getRouteParameters());
    $this->assertStringStartsWith('/surface-test/node/kitchen/', $link->toString());
    // A node the URL names has no one URL: the kitchen's edit is not
    // linked.
    $this->assertNull($door('kitchen', 'update')[1]);
    // A bundle no resource is derived for lists nothing of it.
    $this->assertFalse($registry->hasDefinition('entity.content:node.article'));

    // The site's catalog prints the same, and lists the route that pins a
    // node no node is as refused, as every visitor is refused.
    $catalog = $this->container->get('data_surface.site_catalog');
    $kitchen = array_column($catalog->describe()['entity.content:node.kitchen']['operations'], 'doors', 'id');
    $this->assertStringContainsString('/surface-test/node/kitchen/add (data_surface_route_test.add_node)', $kitchen['create'][SiteCatalog::FORM]);
    $refused = array_values(array_filter($catalog->refused(), static fn (array $refusal): bool => $refusal['stage'] === SiteCatalog::ROUTE && str_starts_with((string) $refusal['resource'], 'entity.content:')));
    $this->assertSame([
      [
        'entity.content:node.page',
        'update',
        'The data_surface_route_test.edit_missing_page route\'s "id" default names nothing: The "update" operation needs a node as $id, and there is none with the id "999".',
      ],
    ], array_map(static fn (array $refusal): array => [$refusal['resource'], $refusal['operation'], $refusal['message']], $refused));
  }

  /**
   * Every bundle's seeded build is kept: nothing in it is the request's.
   *
   * One entry per resource id, however often its page is asked for: the
   * context is the deriver's create, which knows the bundle from its
   * binding, and the route's literal value.
   */
  public function testEachBundlesSeededBuildIsKept(): void {
    $bin = $this->container->get('cache.data_surface_build');
    $bin->deleteAll();
    $resources = $this->container->get('data_surface.resources');
    assert($resources instanceof Resources);
    foreach ([1, 2] as $visit) {
      $resources->forgetBuilds();
      foreach (['page', 'kitchen'] as $bundle) {
        $this->visit('/surface-test/node/' . $bundle . '/welcome');
        $served = $this->served();
        $this->assertSame('Welcome', $this->surfaceForm()['title']['value']['#value']);
        $key = (new \ReflectionMethod(Resources::class, 'memoKey'))->invoke($resources, $served->resource, $served->context, TRUE, TRUE);
        $this->assertNotFalse($bin->get((new \ReflectionMethod(Resources::class, 'cacheId'))->invoke($resources, $served->resource, $key)), $bundle . ', visit ' . $visit);
      }
    }
    // Kernel tests keep every bin in memory, whose entries are read here.
    $cids = array_map('strval', array_keys((new \ReflectionProperty($bin, 'cache'))->getValue($bin)));
    foreach (['page', 'kitchen'] as $bundle) {
      $this->assertCount(1, array_filter($cids, static fn (string $cid): bool => str_starts_with($cid, 'declared:entity.content:node.' . $bundle . ':')), $bundle);
    }
  }

  /**
   * A page whose `_title` cannot name it is titled by the operation's label.
   */
  public function testTheTitleIsTheOperationsLabel(): void {
    $title = function (string $path): string {
      $this->visit($path);
      $request = $this->container->get('request_stack')->getCurrentRequest();
      return (string) $this->container->get('title_resolver')->getTitle($request, $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT));
    };
    // One route for every bundle: the derived create's "Add <bundle>".
    $this->assertSame('Add Basic page', $title('/surface-test/node/page/add-labeled'));
    $this->assertSame('Add Kitchen sink', $title('/surface-test/node/kitchen/add-labeled'));
    // An upcast placeholder, whose raw id `_title` would print.
    $page = Node::create(['type' => 'page', 'title' => 'Page']);
    $page->save();
    $this->assertSame('Edit Basic page', $title('/surface-test/node/page/' . $page->id() . '/edit-labeled'));
    // A URL that names nothing: its page is not found.
    $this->assertSame('Page not found', $title('/surface-test/node/article/add-labeled'));
  }

  /**
   * A node of another bundle, or none, names nothing on the edit page.
   */
  public function testNodeOfAnotherBundleNamesNothing(): void {
    $kitchen = Node::create(['type' => 'kitchen', 'title' => 'Sink']);
    $kitchen->save();
    $page = Node::create(['type' => 'page', 'title' => 'Page']);
    $page->save();
    $resources = $this->container->get('data_surface.resources');
    foreach ([
      'The update operation of entity.content:node.page takes a node of the page bundle.' => $kitchen,
      'The "update" operation needs a node as $id, and there is none with the id "999".' => 999,
    ] as $message => $id) {
      try {
        $resources->operation('entity.content:node.page', 'update', ['id' => $id]);
        $this->fail($message);
      }
      catch (NamesNothingException $e) {
        $this->assertSame($message, $e->getMessage());
        $this->assertSame('id', $e->parameter);
      }
    }

    // The edit page whose path names the kitchen node as a page.
    $this->visit('/surface-test/node/page/' . $kitchen->id() . '/edit');
    $this->assertNamesNothing('The update operation of entity.content:node.page takes a node of the page bundle.', 'id');
    // The page's own node is served, and is what the page is about.
    $this->visit('/surface-test/node/page/' . $page->id() . '/edit');
    $this->assertSame((string) $page->id(), $this->served()->subject);

    // A plain default is the route's own: one that names nothing is the
    // route written wrong, whatever the URL.
    $this->visit('/surface-test/node/page/edit-missing');
    try {
      $this->served();
      $this->fail('A route pinning a node that does not exist was served.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotInstanceOf(NamesNothingException::class, $e);
      $this->assertSame('The data_surface_route_test.edit_missing_page route\'s "id" default names nothing: The "update" operation needs a node as $id, and there is none with the id "999".', $e->getMessage());
    }
  }

}
