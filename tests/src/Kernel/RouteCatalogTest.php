<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\surface\ResourceBuild\FormRouteDoors;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\ResourceBuild\SiteCatalog;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What the site's catalog says of the form routes that serve an operation.
 *
 * The form door names each route with what it says of the operation's
 * keys, and links the first one a URL needs nothing more for. Each route
 * whose path has no variable is held once, by the build the bin keeps for
 * the next request; each key a route lets a link start is found on the
 * operation's build; and a route either refuses is listed under Refused,
 * naming the route and why, as every visitor of it is refused.
 *
 * @see \Drupal\surface\ResourceBuild\FormRouteDoors
 * @see \Drupal\surface\ResourceBuild\SiteCatalog
 * @see docs/catalog-command.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RouteCatalogTest extends SurfaceKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the catalog.
   *
   * @return \Drupal\surface\ResourceBuild\SiteCatalog
   *   The service.
   */
  protected function catalog(): SiteCatalog {
    return $this->container->get('surface.site_catalog');
  }

  /**
   * Reads the form door of each of the card's operations.
   *
   * @return array<string, string>
   *   The door, by operation id.
   */
  protected function formDoors(): array {
    $doors = [];
    foreach ($this->catalog()->describe()['route_test.card']['operations'] as $operation) {
      $doors[$operation['id']] = $operation['doors'][SiteCatalog::FORM] ?? '';
    }
    return $doors;
  }

  /**
   * The form door names each route with what it says of the keys.
   */
  public function testTheFormDoorSaysWhatEachRouteSays(): void {
    $doors = $this->formDoors();
    foreach ([
      // A route that says nothing more.
      '/surface-test/card/add (surface_route_test.add), ',
      // Literal values it fixes and fills, as written.
      '/surface-test/card/add-welcome (surface_route_test.add_welcome) [fixes title: Welcome, greeting.format: html, envelope.color: cream; fills greeting.value: Start here., copies: 3, envelope.stamps: 2]',
      // A reference, as written.
      '/surface-test/card/add/{color} (surface_route_test.add_colored) [fixes envelope.color: {color}]',
      '/surface-test/card/add-copies/{copies} (surface_route_test.add_copies) [fills copies: {copies}]',
      // The keys a link may start, and the query parameter each reads.
      '/surface-test/card/add-subject (surface_route_test.add_subject) [starts title from ?subject, greeting.value from ?greeting]',
    ] as $route) {
      $this->assertStringContainsString($route, $doors['create']);
    }
    // A React page a module serves is no form: the React door lists it
    // (ReactRoutePageTest).
    $this->assertStringNotContainsString('surface_route_test.react_add', $doors['create']);
    // A plain default pinning a parameter of the operation.
    $this->assertStringContainsString('/surface-test/card/send-friends (surface_route_test.send_friends) [pins to: friends; fills title: Dear friends]', $doors['send']);
    // An update's route, whose card the path names.
    $this->assertStringContainsString('/surface-test/card/{card}/edit-queried (surface_route_test.edit_queried) [starts title from ?subject]', $doors['update']);
  }

  /**
   * What each route gets wrong is listed under Refused, naming the route.
   */
  public function testTheRefusedTableListsWhatEachRouteGetsWrong(): void {
    $refused = [];
    foreach ($this->catalog()->refused() as $refusal) {
      if ($refusal['stage'] === SiteCatalog::ROUTE) {
        $refused[(string) $refusal['operation']][] = $refusal['message'];
      }
    }
    // In whatever order the router lists its routes.
    $refused = array_map(static function (array $messages): array {
      sort($messages);
      return $messages;
    }, $refused);
    $this->assertSame([
      'create' => [
        // Seeds the card refuses: a value, a path that names no key.
        'The surface_route_test.add_too_many_stamps route: The "create" operation sets "envelope.stamps" on route_test.card to 9, which the key does not accept: This value should be between 1 and 5.',
        'The surface_route_test.add_tucked route: The "create" operation: route_test.card has no key "envelope.flap". A fixed value names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).',
        // Keys no link may start.
        'The surface_route_test.query_inside route\'s "_surface_query": "paper_settings.weight" on route_test.card is inside "paper_settings", a key with variants, each starting from its own defaults, so a prefill does not reach it.',
        'The surface_route_test.query_map route\'s "_surface_query": "greeting" on route_test.card holds a map: name each of its keys, as "greeting.<key>".',
        'The surface_route_test.query_secret route\'s "_surface_query": "code" on route_test.card is a secret, which no form draws, so no prefill starts it.',
        'The surface_route_test.query_undeclared route\'s "_surface_query": route_test.card has no key "greeting.font". A prefill names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).',
      ],
      'send' => [
        // A key fixed over the operation's own fixed value, a plain
        // default nothing reads, and one the operation refuses.
        'The surface_route_test.send_cream route fixes "envelope.color", which the "send" operation already narrows with a Choice of its own: the route\'s would take its place. A key the operation narrows to a list is the operation\'s to fix.',
        'The surface_route_test.send_misnamed route has the plain default "too", which nothing it serves reads: the "send" operation takes $to, $from. A default with no leading underscore is a route parameter, which the operation reads by its name.',
        'The surface_route_test.send_nobody route: There is no "nobody" list; the lists are family, friends.',
      ],
    ], $refused);
    // A route with a variable is the URL's to finish, so it is not held:
    // the update that fills a key, the delete that fixes one, the misplaced
    // reference are refused when a URL is matched, not here.
  }

  /**
   * A route with no variable in its path is held once, in the bin.
   *
   * By the declared build the bin keeps, which the next request to its
   * page reads rather than builds.
   */
  public function testEachLiteralRouteIsHeldOnceForTheNextRequest(): void {
    $this->container->get('cache.surface_build')->deleteAll();
    $this->catalog()->refused();
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    // The next request, which finds nothing in its memo: the page's own
    // hold reads what the catalog held, and builds nothing.
    $resources->forgetBuilds();
    $counts = $resources->buildCounts();
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_route_test.add_welcome');
    $served = OperationRoute::fromRouteMatch(new RouteMatch('surface_route_test.add_welcome', $route), $this->container->get('surface.resource_registry'), $this->container->get('surface.operation_arguments'), $resources);
    $this->assertSame('Welcome', $served->context->constraints()['title']['Choice']['choices'][0]);
    $after = $resources->buildCounts();
    $this->assertSame([$counts['built'], $counts['cached'] + 1], [$after['built'], $after['cached']]);
  }

  /**
   * A route is linked when a URL needs nothing more than it says.
   *
   * Each variable of its path given a default, or none in it; one with a
   * variable nothing fills is listed and not linked.
   */
  public function testFormDoorIsLinkedWhereUrlNeedsNothingMore(): void {
    $registry = $this->container->get('surface.resource_registry');
    $card = $registry->getDefinition('route_test.card');
    $create = $registry->getOperation($card->name(), 'create');
    $routes = $this->container->get('router.route_provider');
    $linked = function (string $name) use ($routes, $card, $create): array {
      $provider = $this->createMock(RouteProviderInterface::class);
      $provider->method('getAllRoutes')->willReturn(new \ArrayIterator([$name => $routes->getRouteByName($name)]));
      $doors = new FormRouteDoors($provider);
      $this->assertStringContainsString('(' . $name . ')', $doors->doors($card, $create, TRUE, FALSE)[SiteCatalog::FORM], 'Listed.');
      $link = $doors->links($card, $create, TRUE, FALSE)[SiteCatalog::FORM] ?? NULL;
      return $link === NULL ? [] : [$link->getRouteName(), $link->toString()];
    };
    $this->assertSame(['surface_route_test.add', '/surface-test/card/add'], $linked('surface_route_test.add'));
    // Its one variable has a default, so the URL leaves it out.
    $this->assertSame(['surface_route_test.add_optional', '/surface-test/card/add-optional'], $linked('surface_route_test.add_optional'));
    // A variable nothing fills: listed, not linked.
    $this->assertSame([], $linked('surface_route_test.add_colored'));
    // Every route of the card's create, the first that can be linked.
    $this->assertSame('surface_route_test.add', $this->catalog()->links('route_test.card', 'create')[SiteCatalog::FORM]->getRouteName());
  }

}
