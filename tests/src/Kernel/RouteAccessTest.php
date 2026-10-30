<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\EventSubscriber\ParamConverterSubscriber;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\surface\Access\OperationRouteSubscriber;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface_examples_node_type\Resource\NodeTypeResource;
use Drupal\surface_react\Access\ServedOperationAccessCheck;
use Drupal\surface_react\Controller\ResourceReactPage;
use Drupal\surface_resource_test\Resource\RecipeResource;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\surface\Kernel\Fixture\RefusedRoutes;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Who is asked what, and in which order, at a route served by an operation.
 *
 * The operation's permission first, from the resource and operation ids
 * alone, so an account without it hears the permission's reason and
 * nothing of what the URL names. A value of the URL that names nothing is
 * a page not found for an account the permission lets in: let through in
 * the request's own dispatch, for the form to answer 404, and refused when
 * access is asked about a link, so no link to it is drawn; let through
 * only to a controller known to answer it so, the operation form or a
 * React page, and refused saying nothing at any other. What a route
 * alone shows to be written wrong is refused when the router is rebuilt,
 * and again, alike, when a route no rebuild saw is matched, before the
 * permission is asked. The React
 * page asks the same gate, and answers a URL that names nothing as the
 * form's route does. An operation that declares no permission sorts no
 * account before the URL's value is read, so a value that names nothing
 * there is refused to every account, saying nothing.
 *
 * @see \Drupal\surface\Access\OperationAccessCheck
 * @see \Drupal\surface\Access\OperationGate
 * @see \Drupal\surface\Access\OperationRouteSubscriber
 * @see docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RouteAccessTest extends SurfaceKernelTestBase {

  use OperationRouteTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_examples_entity',
    'surface_examples_node_type',
    'surface_react',
    'surface_resource_test',
    'surface_route_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface']);
    $this->setUpCurrentUser(admin: TRUE);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Asks the operation form's route about the route on the stack.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param bool $dispatch
   *   TRUE to ask as the request's own dispatch asks, handing the request
   *   in; FALSE to ask as a link is asked, with none.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function formAccess(AccountInterface $account, bool $dispatch = TRUE): AccessResultInterface {
    return $this->container->get('surface.operation_access')->access(
      $this->container->get('current_route_match'),
      $account,
      $dispatch ? $this->container->get('request_stack')->getCurrentRequest() : NULL,
    );
  }

  /**
   * Makes an account holding exactly some permissions, none declared.
   *
   * @param string[] $permissions
   *   The permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function account(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    $account->method('id')->willReturn(2);
    $account->method('getRoles')->willReturn(['authenticated']);
    return $account;
  }

  /**
   * Builds an edit page of a content type whose path a requirement bounds.
   *
   * No converter loads the content type, so the operation reads the id
   * itself: an id no content type has names nothing.
   *
   * @return \Symfony\Component\Routing\Route
   *   The route.
   */
  protected static function editTypeRoute(): Route {
    return new Route('/surface-test/types/{type}/edit', [
      '_form' => SurfaceOperationForm::class,
      '_title' => 'Edit content type',
      OperationRoute::RESOURCE => NodeTypeResource::class,
      OperationRoute::OPERATION => 'update',
    ], ['type' => '[a-z_]+']);
  }

  /**
   * A value that names nothing is let through to a 404, and draws no link.
   */
  public function testValueThatNamesNothingIsNotFound(): void {
    $admin = $this->container->get('current_user');
    $this->visit('/surface-test/card/add/red');
    // In the request's own dispatch: let through, for this request alone,
    // and the form answers that the page is not found.
    $this->assertSame(['allowed', '', 0], static::answer($this->formAccess($admin)));
    try {
      $this->surfaceForm();
      $this->fail('A color the envelope does not come in was served.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertStringStartsWith('The surface_route_test.add_colored route reads "{color}" from its path, and the value names nothing here: ', $e->getMessage());
    }
    // Asked about a link: refused, for this request alone, saying nothing.
    $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($admin, FALSE)));
    // A menu link and a local task ask Url::access(), which hands no
    // request in: no link to the page is drawn, and one to a color the
    // envelope comes in is.
    $this->assertFalse(Url::fromRoute('surface_route_test.add_colored', ['color' => 'red'])->access($admin));
    $this->assertTrue(Url::fromRoute('surface_route_test.add_colored', ['color' => 'cream'])->access($admin));
  }

  /**
   * A URL that names nothing is let through only to a controller that 404s.
   *
   * The operation form and a React page served at a module's route read
   * the route's context before they serve anything, and answer a value
   * that names nothing with a page not found. Any other controller may
   * serve the URL without reading what it names, so it is refused, saying
   * nothing, for this request alone, before and after the permission is
   * asked; a value that names something is the access class's, as at any
   * route.
   */
  public function testOnlyControllersThatAnswerNotFoundAreLetThrough(): void {
    $holder = $this->createUser([NodeTypeResource::PERMISSION, 'administer content types']);
    $form = static::editTypeRoute();
    $controlled = static fn (string $controller): Route => (clone $form)->setDefaults(['_controller' => $controller] + array_diff_key($form->getDefaults(), ['_form' => TRUE]));
    $foreign = $controlled('\Drupal\system\Controller\SystemController::systemAdminMenuBlockPage');
    foreach ([
      'the operation form' => [$form, 'allowed'],
      'a React page' => [$controlled('\Drupal\surface_react\Controller\ResourceReactPage::served'), 'allowed'],
      'another controller' => [$foreign, 'forbidden'],
    ] as $door => [$route, $state]) {
      $this->serveBuilt('surface_route_test.edit_type', $route, ['type' => 'ghost']);
      $this->assertSame([$state, '', 0], static::answer($this->formAccess($holder)), $door);
    }
    $answers = [];
    foreach (['form' => $form, 'foreign' => $foreign] as $door => $route) {
      $this->serveBuilt('surface_route_test.edit_type', $route, ['type' => 'article']);
      $answers[$door] = static::answer($this->formAccess($holder));
    }
    $this->assertSame('allowed', $answers['form'][0]);
    $this->assertSame($answers['form'], $answers['foreign'], 'A content type that exists is the access class\'s at either.');

    // A resource id the path fills that no resource has, asked before any
    // permission: refused at another controller to any account alike.
    $any = new Route('/surface-test/{entity_type}/{bundle}/add', [
      '_controller' => '\Drupal\system\Controller\SystemController::systemAdminMenuBlockPage',
      '_title' => 'Add content',
      OperationRoute::RESOURCE => 'entity.content:{entity_type}.{bundle}',
      OperationRoute::OPERATION => 'create',
    ], ['entity_type' => '[a-z_]+', 'bundle' => '[a-z_]+']);
    $this->serveBuilt('surface_route_test.add_any', $any, ['entity_type' => 'node', 'bundle' => 'article']);
    $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($this->createUser([]))));
  }

  /**
   * An account without the operation's permission hears that, and no more.
   */
  public function testThePermissionIsAskedFirst(): void {
    $visitor = $this->createUser([]);
    $answers = [];
    foreach (['red', 'cream'] as $color) {
      $this->visit('/surface-test/card/add/' . $color);
      $answers[$color] = static::answer($this->formAccess($visitor));
    }
    $this->assertSame(['forbidden', "The 'administer site configuration' permission is required.", -1], $answers['red']);
    $this->assertSame($answers['red'], $answers['cream'], 'A value that names nothing and one that names something read alike.');
  }

  /**
   * A plain default the operation refuses is the route's, saying why.
   */
  public function testPlainDefaultTheOperationRefusesIsTheRoutes(): void {
    $this->visit('/surface-test/card/send-nobody');
    $this->assertSame(['forbidden', 'There is no "nobody" list; the lists are family, friends.', -1], static::answer($this->formAccess($this->container->get('current_user'))));
  }

  /**
   * An id no entity has is asked about only once the permission allows.
   */
  public function testAnIdNamingNothingAsksThePermissionFirst(): void {
    $stranger = $this->createUser([]);
    $holder = $this->createUser([NodeTypeResource::PERMISSION]);
    foreach (['article', 'ghost'] as $type) {
      $this->serveBuilt('surface_route_test.edit_type', static::editTypeRoute(), ['type' => $type]);
      $this->assertSame(['forbidden', "The 'administer data surface node type demo' permission is required.", -1], static::answer($this->formAccess($stranger)), $type);
    }
    // The account the permission lets in hears that the id names nothing.
    $this->assertSame(['allowed', '', 0], static::answer($this->formAccess($holder)));
    $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($holder, FALSE)));
  }

  /**
   * With no permission to ask first, a value naming nothing is refused mute.
   *
   * A derived content entity operation declares no permission, since
   * entity access answers once the node is loaded, so nothing sorts an
   * account before the URL's value is read. A node of another bundle, or
   * an id no node has, is refused to every account alike, for this
   * request alone, with no reason: the page not found needs a permission
   * to ask first. The React page gives its one fixed refusal for the same
   * URL.
   */
  public function testValueNamingNothingWithNoPermissionIsRefusedSayingNothing(): void {
    // A resource is derived for a bundle with a stored form display.
    foreach (['page' => 'Basic page', 'kitchen' => 'Kitchen sink'] as $bundle => $label) {
      NodeType::create(['type' => $bundle, 'name' => $label])->save();
      EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ])->setComponent('title', ['type' => 'string_textfield'])->save();
    }
    $kitchen = Node::create(['type' => 'kitchen', 'title' => 'Sink']);
    $kitchen->save();
    $accounts = [
      'anonymous' => new AnonymousUserSession(),
      'editor' => $this->createUser(['access content', 'edit any page content', 'edit any kitchen content']),
    ];
    $react = $this->container->get('surface_react.access');
    $routes = $this->container->get('router.route_provider');
    $ids = ['resource' => 'entity.content:node.page', 'operation' => 'update'];
    $page = new RouteMatch('surface_react.page', $routes->getRouteByName('surface_react.page'), $ids, $ids);
    foreach (['another bundle' => (string) $kitchen->id(), 'none' => '999'] as $case => $id) {
      if ($case === 'none') {
        // A converter answers 404 for it before access is asked; a route
        // no converter saw hands the raw id on.
        $edit = $routes->getRouteByName('surface_route_test.edit_node');
        $this->serveBuilt('surface_route_test.edit_node', $edit, ['node_type' => 'page', 'id' => $id]);
      }
      else {
        $this->visit('/surface-test/node/page/' . $id . '/edit');
      }
      $request = Request::create('/surface-react/entity.content:node.page/update', 'GET', ['id' => $id]);
      $request->setSession($this->container->get('request_stack')->getSession());
      foreach ($accounts as $who => $account) {
        $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($account)), $case . ', ' . $who);
        $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($account, FALSE)), $case . ', ' . $who . ', a link');
        $this->assertSame(['forbidden', ServedOperationAccessCheck::REFUSED, 0], static::answer($react->access($page, $account, $request)), $case . ', ' . $who . ', the React page');
      }
    }
  }

  /**
   * A permission naming a key is asked of the parameters the URL carries.
   *
   * The recipe's create takes `cook in %kitchen`, which the two ids alone
   * cannot fill. A course the recipe does not offer names nothing, so no
   * context is built to fill it from: the kitchen the path names fills
   * it.
   */
  public function testPermissionNamingKeyIsFilledFromTheParameters(): void {
    $route = new Route('/surface-test/recipes/{kitchen}/{course}/add', [
      '_form' => SurfaceOperationForm::class,
      '_title' => 'Add a recipe',
      OperationRoute::RESOURCE => RecipeResource::class,
      OperationRoute::OPERATION => 'create',
      OperationRoute::FIXED => ['course' => '{course}'],
    ], ['kitchen' => '[a-z]+', 'course' => '[a-z]+']);
    $cook = $this->account(['cook in main']);
    $stranger = $this->account(['cook in pantry']);
    $this->serveBuilt('surface_route_test.add_recipe', $route, ['kitchen' => 'main', 'course' => 'brunch']);
    $this->assertSame(['allowed', '', 0], static::answer($this->formAccess($cook)));
    $this->assertSame(['forbidden', "The 'cook in main' permission is required.", -1], static::answer($this->formAccess($stranger)));
    // A course the recipe offers: the context fills it, as before.
    $this->serveBuilt('surface_route_test.add_recipe', $route, ['kitchen' => 'main', 'course' => 'starter']);
    $this->assertTrue($this->formAccess($cook)->isAllowed());
    $this->assertFalse($this->formAccess($stranger)->isAllowed());
  }

  /**
   * A resource id the path fills that no resource has is not found, to any.
   *
   * Nothing is there to hold a permission for, so the answer comes before
   * any, as the API answers a path that names no resource; the refusal
   * names the variable the URL left out when it left one out.
   */
  public function testResourceIdThePathFillsNamingNothingIsNotFound(): void {
    $route = new Route('/surface-test/{entity_type}/{bundle}/add', [
      '_form' => SurfaceOperationForm::class,
      '_title' => 'Add content',
      OperationRoute::RESOURCE => 'entity.content:{entity_type}.{bundle}',
      OperationRoute::OPERATION => 'create',
    ], ['entity_type' => '[a-z_]+', 'bundle' => '[a-z_]+']);
    $this->serveBuilt('surface_route_test.add_any', $route, ['entity_type' => 'node', 'bundle' => 'article']);
    $stranger = $this->createUser([]);
    $this->assertSame(['allowed', '', 0], static::answer($this->formAccess($stranger)));
    $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($stranger, FALSE)));
    $registry = $this->container->get('surface.resource_registry');
    foreach ([
      'bundle' => ['entity_type' => 'node'],
      'entity_type' => ['entity_type' => 'node', 'bundle' => 'article'],
    ] as $parameter => $raw) {
      try {
        OperationRoute::named(new RouteMatch('surface_route_test.add_any', $route, $raw, $raw), $registry);
        $this->fail('A resource id no resource has was named.');
      }
      catch (NamesNothingException $e) {
        $this->assertSame($parameter, $e->parameter);
      }
    }
  }

  /**
   * The checks run once core has written each parameter's converter.
   */
  public function testTheChecksRunAfterTheConverters(): void {
    [$method, $priority] = OperationRouteSubscriber::getSubscribedEvents()[RoutingEvents::ALTER];
    $this->assertSame('onAlterRoutes', $method);
    $converters = ParamConverterSubscriber::getSubscribedEvents()[RoutingEvents::ALTER][0][1];
    $this->assertSame(-220, $converters);
    $this->assertLessThan($converters, $priority);
    // A variable only its type's converter bounds, which the rebuild
    // refuses while no converter is written (the rebuild test below), is
    // taken once core has written one.
    [$unbounded] = RefusedRoutes::all()['surface_route_test.add_node_unbounded'];
    $collection = new RouteCollection();
    $collection->add('surface_route_test.add_node_unbounded', $unbounded);
    $this->container->get('paramconverter_subscriber')->onRoutingRouteAlterSetParameterConverters(new RouteBuildEvent($collection));
    $this->container->get('surface.operation_route_subscriber')->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame('TRUE', $unbounded->getRequirement(OperationRoute::ACCESS));
  }

  /**
   * What the route alone shows is refused at the rebuild and at match alike.
   *
   * Each refusal of the rebuild names the route; matched by hand, as a
   * route no rebuild saw is, the route is refused with the same words,
   * before the permission is asked, since it reads nothing of the URL.
   */
  public function testTheRebuildAndTheMatchRefuseAlike(): void {
    $subscriber = $this->container->get('surface.operation_route_subscriber');
    $visitor = $this->createUser([]);
    foreach (RefusedRoutes::all() as $name => [$route, $raw]) {
      $collection = new RouteCollection();
      $collection->add($name, clone $route);
      $refusal = NULL;
      try {
        $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
      }
      catch (\InvalidArgumentException $e) {
        $refusal = $e->getMessage();
      }
      $this->assertIsString($refusal, sprintf('The rebuild took the %s route.', $name));
      $this->assertStringStartsWith('The ' . $name . ' route', $refusal);
      $this->serveBuilt($name, $route, $raw);
      $this->assertRouteRefused($refusal);
      $this->assertSame(['forbidden', $refusal], array_slice(static::answer($this->formAccess($visitor)), 0, 2), sprintf('The %s route, to an account without the permission.', $name));
    }
  }

  /**
   * The rebuild's refusals that are new each say what is wrong and the fix.
   */
  public function testTheRebuildsRefusalsSayTheFix(): void {
    $refusals = [];
    foreach (RefusedRoutes::all() as $name => [$route]) {
      try {
        OperationRoute::assertWritten($route, $name);
      }
      catch (\InvalidArgumentException $e) {
        $refusals[$name] = $e->getMessage();
      }
    }
    $this->assertSame(array_keys(RefusedRoutes::all()), array_keys($refusals), 'Every one of them is refused.');
    $this->assertSame([
      'surface_route_test.add_unbounded' => 'The surface_route_test.add_unbounded route fixes "envelope.color" to "{color}", and nothing bounds that variable of its path /surface-test/card/add-unbounded/{color}: give it a requirement (requirements.color) or a type a converter loads (options.parameters.color.type), so a reference reads only a value the route allows.',
      'surface_route_test.add_copies_unbounded' => 'The surface_route_test.add_copies_unbounded route fills "copies" to "{copies}", and nothing bounds that variable of its path /surface-test/card/add-copies-unbounded/{copies}: give it a requirement (requirements.copies) or a type a converter loads (options.parameters.copies.type), so a reference reads only a value the route allows.',
      'surface_route_test.add_node_misnamed' => 'The surface_route_test.add_node_misnamed route serves "entity.content:node.{bundle}", and its path /surface-test/node/{node_type}/add-misnamed has no "{bundle}" to fill it from: a resource\'s id names a variable of the route\'s own path.',
      'surface_route_test.add_node_unbounded' => 'The surface_route_test.add_node_unbounded route serves "entity.content:node.{node_type}", and nothing bounds "{node_type}" in its path /surface-test/node/{node_type}/add-unbounded: give it a requirement (requirements.node_type) or a type a converter loads (options.parameters.node_type.type), so the id a URL fills is one the route allows.',
      'surface_route_test.query_dotted' => 'The surface_route_test.query_dotted route starts "greeting.value" from the query parameter "greeting.value": PHP renames a "." or a space in a query parameter\'s name to "_" and reads "[" as the start of an array before the form reads it, so no link could start the key; a query name holds letters, digits, "_" and "-" alone. Name another query parameter.',
      'surface_route_test.react_fixed' => 'The surface_route_test.react_fixed route carries "_surface_fixed", and ResourceReactPage::served serves it: the page writes through the operation\'s own API path, which holds a value to the operation\'s constraints alone, so a key the page stated fixed would take any value there. A React page starts keys and fixes none: start them under "_surface_defaults", or serve the route with the operation form.',
    ], array_intersect_key($refusals, array_flip([
      'surface_route_test.add_unbounded',
      'surface_route_test.add_copies_unbounded',
      'surface_route_test.add_node_misnamed',
      'surface_route_test.add_node_unbounded',
      'surface_route_test.query_dotted',
      'surface_route_test.react_fixed',
    ])));
  }

  /**
   * The form's route and the React page answer a URL that names nothing alike.
   *
   * One content type id no content type has, at the form's edit page and
   * at the React page for the same operation, each asked as the request's
   * own dispatch asks: let through for the account the permission lets
   * in, each controller then answering that the page is not found, and
   * refused for any other; asked about a link, neither is drawn.
   */
  public function testBothPageDoorsAnswerAlike(): void {
    $holder = $this->createUser([NodeTypeResource::PERMISSION]);
    $stranger = $this->createUser([]);
    $react = $this->container->get('surface_react.access');
    $page = $this->container->get('router.route_provider')->getRouteByName('surface_react.page');
    $ids = ['resource' => 'node.type', 'operation' => 'update'];
    $match = new RouteMatch('surface_react.page', $page, $ids, $ids);
    $request = Request::create('/surface-react/node.type/update', 'GET', ['type' => 'ghost']);
    $request->setSession($this->container->get('request_stack')->getSession());
    $this->serveBuilt('surface_route_test.edit_type', static::editTypeRoute(), ['type' => 'ghost']);
    foreach ([['allowed', $holder], ['forbidden', $stranger]] as [$state, $account]) {
      $this->assertSame($state, static::answer($react->access($match, $account, $request))[0]);
      $this->assertSame($state, static::answer($this->formAccess($account))[0]);
    }
    // Let through, each answers that the page is not found, saying why.
    $why = 'The "update" operation needs a node_type as $type, and there is none with the id "ghost".';
    try {
      $this->surfaceForm();
      $this->fail('The form was built.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame($why, $e->getMessage());
    }
    $controller = ResourceReactPage::create($this->container);
    try {
      $controller->page($request, 'node.type', 'update');
      $this->fail('The React page was built.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame($why, $e->getMessage());
    }
    // Asked about a link, which hands no request in: neither is drawn. The
    // React page reads its parameters off the request that stands in.
    $this->assertSame(['forbidden', '', 0], static::answer($this->formAccess($holder, FALSE)));
    $this->container->get('request_stack')->push($request);
    $this->assertSame(['forbidden', '', 0], static::answer($react->access($match, $holder)));

    // A React page whose path names no resource, or no operation, is not
    // found, to any account, as the API answers such a path.
    foreach ([
      'No resource or structure "nothing.here" was discovered' => ['nothing.here', 'update'],
      'node.type has no "nothing" operation' => ['node.type', 'nothing'],
    ] as $why => [$resource, $operation]) {
      $ids = ['resource' => $resource, 'operation' => $operation];
      $there = Request::create('/surface-react/' . $resource . '/' . $operation);
      $this->assertSame(['allowed', '', 0], static::answer($react->access(new RouteMatch('surface_react.page', $page, $ids, $ids), $stranger, $there)));
      try {
        $controller->page($there, $resource, $operation);
        $this->fail('The React page was built.');
      }
      catch (NotFoundHttpException $e) {
        $this->assertStringStartsWith($why, $e->getMessage());
      }
    }
  }

}
