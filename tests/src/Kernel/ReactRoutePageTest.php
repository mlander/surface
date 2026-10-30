<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Url;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\ResourceBuild\NamesNothingException;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\SiteCatalog;
use Drupal\data_surface_react\Controller\ResourceReactPage;
use Drupal\data_surface_route_test\Storage\CardStorage;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * The React page a module serves an operation at, at a route of its own.
 *
 * The route names the resource and the operation as a route the
 * operation form serves does, and is gated by the same access check. The
 * page hands the app what every React page of the operation hands it,
 * and what the values start from on this page: the route's defaults as
 * the build holds them, and what a link sends for each key the route
 * lists, held to its key and left out when the key refuses it. It fixes
 * nothing, a value of its URL that names nothing is a page not found, and
 * it is titled by the operation's label. The catalog lists it under the
 * React door, beside the operation's own page, and not as a form.
 *
 * @see \Drupal\data_surface_react\Controller\ResourceReactPage::served()
 * @see docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReactRoutePageTest extends DataSurfaceKernelTestBase {

  use HttpKernelUiHelperTrait;
  use OperationRouteTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_react',
    'data_surface_route_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the page's controller.
   *
   * @return \Drupal\data_surface_react\Controller\ResourceReactPage
   *   The controller.
   */
  protected function controller(): ResourceReactPage {
    return ResourceReactPage::create($this->container);
  }

  /**
   * Builds the page served at the route on the stack.
   *
   * @return array
   *   The render array.
   */
  protected function page(): array {
    return $this->controller()->served(
      $this->container->get('current_route_match'),
      $this->container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * Reads what a page hands the app.
   *
   * @param array $page
   *   The page's render array.
   *
   * @return array
   *   Its drupalSettings.dataSurfaceReact.
   */
  protected static function settings(array $page): array {
    return $page['#attached']['drupalSettings']['dataSurfaceReact'];
  }

  /**
   * Reads what the operation's own page hands the app for some parameters.
   *
   * @param string $operation
   *   The card's operation.
   * @param array<string, string> $parameters
   *   Its parameters, as the page's query string carries them.
   *
   * @return array
   *   Its drupalSettings.dataSurfaceReact.
   */
  protected function ownPage(string $operation, array $parameters = []): array {
    $request = Request::create('/surface-react/route_test.card/' . $operation, 'GET', $parameters);
    return static::settings($this->controller()->page($request, 'route_test.card', $operation));
  }

  /**
   * The page hands the app what its route starts, beside what any page has.
   */
  public function testThePageHandsTheAppWhatItsRouteStarts(): void {
    $this->visit('/surface-test/card/react/4?occasion=wedding&stamps=3&greeting=Hi%20there');
    $page = $this->page();
    $settings = static::settings($page);
    // The route's defaults, each as the build holds it: the reference read
    // from the path in its key's type, as the contract's `default` would
    // state it.
    $this->assertSame(['defaults' => ['title' => 'Dear you', 'copies' => 4]], $settings['seeds']);
    // What the link sends for each key the route lists, in its key's type,
    // by key path.
    $this->assertSame(['occasion' => 'wedding', 'envelope.stamps' => 3, 'greeting.value' => 'Hi there'], $settings['prefill']);
    // Beside them, what the operation's own page hands the app: the app's
    // documents, its narrowing and its write are the operation's.
    $this->assertSame($this->ownPage('create'), array_diff_key($settings, ['seeds' => TRUE, 'prefill' => TRUE]));
    // The page varies by each query parameter a link may start a key from.
    foreach (['occasion', 'stamps', 'greeting'] as $name) {
      $this->assertContains('url.query_args:' . $name, $page['#cache']['contexts']);
    }
  }

  /**
   * What the key refuses starts nothing, and no key is fixed.
   */
  public function testWhatTheKeyRefusesStartsNothing(): void {
    $this->visit('/surface-test/card/react/4?occasion=funeral&stamps=9&greeting=');
    $settings = static::settings($this->page());
    // None of the occasions, past the stamps' range, and sent empty: each
    // left out, saying nothing.
    $this->assertSame([], $settings['prefill']);
    $this->assertSame([], $this->container->get('messenger')->all());
    // The route fixes nothing, and the page says nothing of fixing.
    $this->assertSame(['defaults'], array_keys($settings['seeds']));
    $this->assertArrayNotHasKey('fixed', $settings);
  }

  /**
   * Each default is named by its key's path, and none starts a fixed key.
   *
   * A map the route starts whole is named key by key, as what a link
   * sends is. The send fixes the envelope's color, so the route's default
   * for it starts nothing: the key keeps what the build says, as the
   * operation's own path, where the app writes, says it.
   */
  public function testEachDefaultIsNamedByItsKeysPath(): void {
    $page = [
      '_controller' => '\Drupal\data_surface_react\Controller\ResourceReactPage::served',
      '_title' => 'A card',
      OperationRoute::RESOURCE => 'route_test.card',
    ];
    $this->serveBuilt('data_surface_route_test.react_greeting', new Route('/surface-test/card/react-greeting', [
      OperationRoute::OPERATION => 'create',
      OperationRoute::DEFAULTS => ['greeting' => '{"value": "Hi"}'],
    ] + $page));
    $this->assertSame(['defaults' => ['greeting.format' => 'plain', 'greeting.value' => 'Hi']], static::settings($this->page())['seeds']);

    $this->serveBuilt('data_surface_route_test.react_send_cream', new Route('/surface-test/card/react-send-cream', [
      OperationRoute::OPERATION => 'send',
      OperationRoute::DEFAULTS => ['title' => 'Dear friends', 'envelope.color' => 'cream'],
    ] + $page));
    $this->assertSame(['defaults' => ['title' => 'Dear friends']], static::settings($this->page())['seeds']);
  }

  /**
   * A value of the URL that names nothing is a page not found.
   */
  public function testValueThatNamesNothingIsNotFound(): void {
    $this->visit('/surface-test/card/react/40');
    try {
      $this->page();
      $this->fail('Forty copies of a card were served.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame('The data_surface_route_test.react_add route reads "{copies}" from its path, and the value names nothing here: The "create" operation sets "copies" on route_test.card to "40", which the key does not accept: This value should be between 1 and 10.', $e->getMessage());
      $this->assertInstanceOf(NamesNothingException::class, $e->getPrevious());
    }
  }

  /**
   * The page is served in the request's own dispatch, its 404 too.
   *
   * The router matches the route, its access check lets the account in,
   * and the controller and the title callback are handed what they name:
   * the page, titled by the operation's label, hands the app what its
   * route starts. A value that names nothing is let through, and the
   * page answers that it is not found; an account the permission refuses
   * is refused.
   */
  public function testTheRequestsOwnDispatchServesThePage(): void {
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Drupal')->save();
    $this->drupalGet('/surface-test/card/react/4?occasion=wedding');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals('Add a card | Drupal');
    $this->assertSession()->elementExists('css', '#' . ResourceReactPage::MOUNT);
    $json = $this->getSession()->getPage()->find('css', 'script[data-drupal-selector="drupal-settings-json"]');
    $this->assertNotNull($json);
    $settings = json_decode($json->getText(), TRUE)['dataSurfaceReact'];
    $this->assertSame(['defaults' => ['title' => 'Dear you', 'copies' => 4]], $settings['seeds']);
    $this->assertSame(['occasion' => 'wedding'], $settings['prefill']);

    $this->drupalGet('/surface-test/card/react/40');
    $this->assertSession()->statusCodeEquals(404);

    $this->setUpCurrentUser();
    $this->drupalGet('/surface-test/card/react/4');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The page is answered by the access check the operation form's route has.
   *
   * The same operation at the React page and at the form's route, each
   * fed the copies by its path: the same answer for every account, in the
   * request's own dispatch and for a link, a value that names nothing
   * included.
   */
  public function testThePageIsAnsweredAsTheFormsRouteIs(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('data_surface_route_test.react_add');
    $this->assertSame('TRUE', $route->getRequirement(OperationRoute::ACCESS));
    $check = $this->container->get('data_surface.operation_access');
    $accounts = [
      'admin' => $this->container->get('current_user'),
      'stranger' => $this->createUser([]),
    ];
    $answers = [];
    foreach (['react' => '/surface-test/card/react/', 'form' => '/surface-test/card/add-copies/'] as $door => $path) {
      foreach (['4', '40'] as $copies) {
        $this->visit($path . $copies);
        $match = $this->container->get('current_route_match');
        $request = $this->container->get('request_stack')->getCurrentRequest();
        foreach ($accounts as $who => $account) {
          $answers[$door][$copies][$who] = [
            static::answer($check->access($match, $account, $request)),
            static::answer($check->access($match, $account)),
          ];
        }
      }
    }
    $this->assertSame($answers['form'], $answers['react']);
    $permission = ['forbidden', "The 'administer site configuration' permission is required.", -1];
    $this->assertSame([
      // Let through to the page not found, and no link drawn to it.
      'admin' => [['allowed', '', 0], ['forbidden', '', 0]],
      'stranger' => [$permission, $permission],
    ], $answers['react']['40']);
    $this->assertSame('allowed', $answers['react']['4']['admin'][0][0]);
    // No link to a page not found is drawn.
    $this->assertTrue(Url::fromRoute('data_surface_route_test.react_add', ['copies' => 4])->access($accounts['admin']));
    $this->assertFalse(Url::fromRoute('data_surface_route_test.react_add', ['copies' => 40])->access($accounts['admin']));
  }

  /**
   * The page's parameters are its route's, as the API names them.
   *
   * A variable of its path and a plain default alike: the app is handed
   * what the operation's own page hands it for the same parameters.
   */
  public function testTheParametersAreTheRoutes(): void {
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);
    $page = [
      '_controller' => '\Drupal\data_surface_react\Controller\ResourceReactPage::served',
      '_title' => 'A card',
      OperationRoute::RESOURCE => 'route_test.card',
    ];
    $this->serveBuilt('data_surface_route_test.react_edit', new Route('/surface-test/card/{card}/react', [
      OperationRoute::OPERATION => 'update',
      OperationRoute::QUERY => ['title' => 'subject'],
    ] + $page, ['card' => '[a-z]+']), ['card' => 'six'], ['subject' => 'Renamed']);
    $settings = static::settings($this->page());
    $this->assertSame(['card' => 'six'], $settings['parameters']);
    $this->assertSame($this->ownPage('update', ['card' => 'six'])['endpoints'], $settings['endpoints']);
    $this->assertSame('/surface-api/route_test.card/six', $settings['endpoints']['read']);
    // A link may start a key of an update, though no route default may.
    $this->assertSame(['defaults' => []], $settings['seeds']);
    $this->assertSame(['title' => 'Renamed'], $settings['prefill']);

    $this->serveBuilt('data_surface_route_test.react_send', new Route('/surface-test/card/react-send', [
      OperationRoute::OPERATION => 'send',
      'to' => 'friends',
    ] + $page));
    $settings = static::settings($this->page());
    $this->assertSame(['to' => 'friends'], $settings['parameters']);
    $this->assertSame($this->ownPage('send', ['to' => 'friends'])['endpoints'], $settings['endpoints']);
  }

  /**
   * The page is titled by the operation's label, or as a page not found.
   */
  public function testTheTitleIsTheOperationsLabel(): void {
    $this->visit('/surface-test/card/react/4');
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertSame('Add a card', (string) $this->container->get('title_resolver')->getTitle($request, $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT)));

    // A resource id the path fills that no resource has names nothing.
    $kind = new Route('/surface-test/{kind}/react', [
      '_controller' => '\Drupal\data_surface_react\Controller\ResourceReactPage::served',
      '_title_callback' => '\Drupal\data_surface_react\Controller\ResourceReactPage::servedTitle',
      OperationRoute::RESOURCE => 'route_test.{kind}',
      OperationRoute::OPERATION => 'create',
    ], ['kind' => '[a-z]+']);
    foreach (['card' => 'Add a card', 'nothing' => 'Page not found'] as $value => $title) {
      $this->serveBuilt('data_surface_route_test.react_kind', $kind, ['kind' => $value]);
      $this->assertSame($title, (string) $this->controller()->servedTitle($this->container->get('current_route_match')), $value);
    }

    // A route written wrong is no page not found, at the React page and at
    // the operation form's route alike.
    $this->serveBuilt('data_surface_route_test.react_wrong', new Route('/surface-test/card/react-wrong', [
      '_controller' => '\Drupal\data_surface_react\Controller\ResourceReactPage::served',
      OperationRoute::RESOURCE => 'route_test.card',
      OperationRoute::OPERATION => 'nothing',
    ]));
    $match = $this->container->get('current_route_match');
    foreach ([
      'react' => fn (): mixed => $this->controller()->servedTitle($match),
      'form' => static fn (): mixed => DataSurfaceOperationForm::title($match),
    ] as $door => $asked) {
      try {
        $asked();
        $this->fail(sprintf('The %s page was titled.', $door));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertNotInstanceOf(NamesNothingException::class, $e, $door);
        $this->assertStringStartsWith('route_test.card has no "nothing" operation', $e->getMessage(), $door);
      }
    }
  }

  /**
   * The catalog lists the page under the React door, and not as a form.
   */
  public function testTheCatalogListsThePageUnderTheReactDoor(): void {
    $catalog = $this->container->get('data_surface.site_catalog');
    $doors = [];
    foreach ($catalog->describe()['route_test.card']['operations'] as $operation) {
      $doors[$operation['id']] = $operation['doors'];
    }
    // Beside the operation's own page, named as the form door names a
    // route: what it fills, and the keys a link may start.
    $this->assertSame('/surface-react/route_test.card/create, /surface-test/card/react/{copies} (data_surface_route_test.react_add) [fills title: Dear you, copies: {copies}; starts occasion from ?occasion, envelope.stamps from ?stamps, greeting.value from ?greeting]', $doors['create'][SiteCatalog::REACT]);
    $this->assertStringNotContainsString('data_surface_route_test.react_add', $doors['create'][SiteCatalog::FORM]);
    // An operation no module serves a page for has its own alone.
    $this->assertSame('/surface-react/route_test.card/update', $doors['update'][SiteCatalog::REACT]);
    // The operation's own page is the door's link.
    $this->assertSame('data_surface_react.page', $catalog->links('route_test.card', 'create')[SiteCatalog::REACT]->getRouteName());

    // An operation that cannot be asked on its own has no page of its own:
    // the pages modules serve it at are its React door, and one a URL needs
    // nothing more for would be its link.
    $registry = $this->container->get('data_surface.resource_registry');
    $card = $registry->getDefinition('route_test.card');
    $create = $registry->getOperation($card->name(), 'create');
    $served = $this->container->get('data_surface_react.catalog_doors');
    $this->assertSame([SiteCatalog::REACT => '/surface-test/card/react/{copies} (data_surface_route_test.react_add) [fills title: Dear you, copies: {copies}; starts occasion from ?occasion, envelope.stamps from ?stamps, greeting.value from ?greeting]'], $served->doors($card, $create, FALSE, FALSE));
    $this->assertSame([], $served->links($card, $create, FALSE, FALSE), 'Its one page needs the copies.');
  }

}
