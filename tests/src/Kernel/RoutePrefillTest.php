<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface_resource_test\Resource\Badge\BadgeResource;
use Drupal\data_surface_resource_test\Resource\Larder\LarderResource;
use Drupal\data_surface_resource_test\Resource\Studio\StudioResource;
use Drupal\data_surface_resource_test\Resource\Venue\HallResource;
use Drupal\data_surface_resource_test\Storage\LarderStorage;
use Drupal\data_surface_route_test\Storage\CardStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * What the operation form starts keys from that no build was handed.
 *
 * On the first draw alone: the values the code building the form hands
 * it, then the value a link sends for each key the route lists under
 * `_data_surface_query`, held to its key and dropped when the key refuses
 * it. Neither is the context, so no document states them; a submission's
 * own input stands over them, and a key the build fixes or locks keeps
 * what the build says. A key no such value reaches is the route or the
 * code written wrong, refused on the first draw.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceOperationForm
 * @see docs/forms.md#a-route-that-fixes-or-fills-keys
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RoutePrefillTest extends DataSurfaceKernelTestBase {

  use OperationRouteTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_resource_test',
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
   * Builds the generic form as the route on the stack, as it is first drawn.
   *
   * @param mixed ...$args
   *   What the code building the form hands it.
   *
   * @return array
   *   The form.
   */
  protected function form(mixed ...$args): array {
    return $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class, ...$args);
  }

  /**
   * Posts the generic form as a browser posts it, to the route on the stack.
   *
   * Drawn first, as a browser is handed it, then sent back to the URL it
   * was drawn at with its token: a request whose body is the input. Core
   * reads the input from the body, writes an explicit nothing for each
   * element it leaves out, and an element takes its default only when the
   * input gives it no value.
   *
   * @param array $surface
   *   What the browser sends under the data surface container.
   * @param mixed ...$args
   *   What the code building the form hands it.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state, once the form was processed.
   */
  protected function post(array $surface, mixed ...$args): FormStateInterface {
    $stack = $this->container->get('request_stack');
    $drawn = $stack->getCurrentRequest();
    $form = $this->form(...$args);
    $request = Request::create($drawn->getRequestUri(), 'POST', [
      'form_id' => $form['#form_id'],
      // The page renders the token in place of the form's placeholder.
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'op' => 'Save',
      DataSurfaceOperationForm::SURFACE_KEY => $surface,
    ]);
    $request->setSession($stack->getSession());
    $request->attributes->add($drawn->attributes->all());
    $stack->push($request);
    $state = (new FormState())->addBuildInfo('args', $args)->disableRedirect();
    $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $state);
    return $state;
  }

  /**
   * Asserts the form on the stack is refused on its first draw, saying why.
   *
   * @param string $message
   *   The refusal.
   * @param mixed ...$args
   *   What the code building the form hands it.
   */
  protected function assertRefusedOnTheFirstDraw(string $message, mixed ...$args): void {
    try {
      $this->form(...$args);
      $this->fail(sprintf('The form was drawn: %s', $message));
    }
    catch (\LogicException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * A link starts the keys its route lists, on the first draw, as input.
   */
  public function testLinkStartsTheKeysItsRouteLists(): void {
    $this->visit('/surface-test/card/add-prefilled?subject=Pricing&copies=4&occasion=birthday&stamps=3&name=birthday_card');
    $form = $this->form();
    $surface = $form[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Pricing', $surface['title']['#default_value']);
    // Read by the coercion table, as a form posts it.
    $this->assertSame(4, $surface['copies']['#default_value']);
    $this->assertSame('birthday', $surface['occasion']['#default_value']);
    // A key of the structure the card attaches.
    $this->assertSame(3, $surface['envelope']['stamps']['#default_value']);
    // The machine name a create asks for, which no context knows yet.
    $this->assertSame('birthday_card', $surface['card']['#default_value']);
    // The page varies by each query parameter the route lists.
    foreach (['name', 'subject', 'copies', 'occasion', 'stamps', 'format'] as $name) {
      $this->assertContains('url.query_args:' . $name, $form['#cache']['contexts']);
    }
    // Input, never the context: what the documents state is unchanged.
    $served = $this->served();
    $this->assertSame([], $served->context->defaults());
    $built = $this->container->get('data_surface.resources')->build($served->resource, $served->context);
    $this->assertSame('Hello', $built->getDefault('title'));
    $this->assertSame(1, $built->getDefault('copies'));
  }

  /**
   * What a link sends that the key refuses, or the build fixes, starts nothing.
   */
  public function testWhatTheKeyRefusesOrTheBuildFixesStartsNothing(): void {
    $this->visit('/surface-test/card/add-prefilled?subject=&copies=40&occasion=funeral&format=plain');
    $surface = $this->form()[DataSurfaceOperationForm::SURFACE_KEY];
    // Sent empty: the declared value.
    $this->assertSame('Hello', $surface['title']['#default_value']);
    // Past the key's range, and none of its list: each dropped, saying
    // nothing, and each element starts from the declared value.
    $this->assertSame(1, $surface['copies']['#default_value']);
    $this->assertEmpty($surface['occasion']['#default_value'] ?? NULL);
    $this->assertSame([], $this->container->get('messenger')->all());
    // The route fixes the format: the fixed value stands.
    $this->assertSame('item', $surface['greeting']['format']['#type']);
    $this->assertSame('html', $surface['greeting']['format']['#value']);
  }

  /**
   * A list or a map sent for a key that holds one value starts nothing.
   *
   * The key refuses it before any of its constraints reads it, as the
   * pipeline refuses it on Save, so the form is drawn from what is stored
   * or declared, as for any value a key refuses.
   */
  public function testListOrMapForOneValueStartsNothing(): void {
    $this->visit('/surface-test/card/add-prefilled?subject[]=Pricing&copies[]=4&stamps[a]=2');
    $surface = $this->form()[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Hello', $surface['title']['#default_value']);
    $this->assertSame(1, $surface['copies']['#default_value']);
    $this->assertSame(1, $surface['envelope']['stamps']['#default_value']);
    $this->assertSame([], $this->container->get('messenger')->all());

    // The code's own values, the same way.
    $this->visit('/surface-test/card/add');
    $surface = $this->form(['title' => ['Handed'], 'envelope' => ['stamps' => [2]]])[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Hello', $surface['title']['#default_value']);
    $this->assertSame(1, $surface['envelope']['stamps']['#default_value']);
  }

  /**
   * A link starts a key an alter mounts, named by its module and its name.
   *
   * The studio's booking fee is a key its alter adds at its mount; the
   * hall's overflow rows are a key of the structure its alter attaches
   * there, found in that structure's own frame. The mount and a module's
   * map only group those keys, so a link names none of them whole.
   */
  public function testKeyAnAlterMountsIsStartedByItsPath(): void {
    $studio = static fn (array $query): Route => new Route('/surface-test/studio/linked', [
      '_form' => DataSurfaceOperationForm::class,
      '_title' => 'Configure the studio',
      OperationRoute::RESOURCE => StudioResource::class,
      OperationRoute::OPERATION => 'configure',
      OperationRoute::QUERY => $query,
    ]);
    $fee = ['third_party_settings.data_surface_resource_test.booking_fee' => 'fee'];
    $this->serveBuilt('data_surface_resource_test.studio.linked', $studio($fee), [], ['fee' => '25']);
    $this->assertSame(25, $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['third_party_settings']['data_surface_resource_test']['booking_fee']['#default_value']);
    // Below the fee's range: the declared value.
    $this->serveBuilt('data_surface_resource_test.studio.linked', $studio($fee), [], ['fee' => '-5']);
    $this->assertSame(0, $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['third_party_settings']['data_surface_resource_test']['booking_fee']['#default_value']);

    $this->serveBuilt('data_surface_resource_test.hall.linked', new Route('/surface-test/hall/linked', [
      '_form' => DataSurfaceOperationForm::class,
      '_title' => 'Configure the hall',
      OperationRoute::RESOURCE => HallResource::class,
      OperationRoute::OPERATION => 'configure',
      OperationRoute::QUERY => ['third_party_settings.data_surface_resource_test.rows' => 'rows'],
    ]), [], ['rows' => '12']);
    $this->assertSame(12, $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['third_party_settings']['data_surface_resource_test']['rows']['#default_value']);

    foreach (['third_party_settings', 'third_party_settings.data_surface_resource_test'] as $path) {
      $this->serveBuilt('data_surface_resource_test.studio.grouped', $studio([$path => 'fee']), [], ['fee' => '{}']);
      $this->assertRefusedOnTheFirstDraw(sprintf('The data_surface_resource_test.studio.grouped route\'s "_data_surface_query": resource_test.studio has no key "%s". A prefill names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).', $path));
    }
  }

  /**
   * A key an alter mounts in a frame the build locks keeps what is stored.
   *
   * The larder's spare rack is restocked, and the alter that applies to a
   * restock adds the rack's supplier at its mount. An account that may
   * stock the rack sees the supplier start from the link; one that may
   * only look at it finds the rack locked, and the link starts nothing
   * there.
   */
  public function testKeyAnAlterMountsInLockedFrameKeepsWhatIsStored(): void {
    \Drupal::state()->set(LarderStorage::key('pantry'), [
      'larder' => 'pantry',
      'spare_rack' => ['third_party_settings' => ['data_surface_resource_test' => ['supplier' => 'Acme']]],
    ]);
    $route = new Route('/surface-test/larder/{larder}/supplied', [
      '_form' => DataSurfaceOperationForm::class,
      '_title' => 'Edit a larder',
      OperationRoute::RESOURCE => LarderResource::class,
      OperationRoute::OPERATION => 'update',
      OperationRoute::QUERY => ['spare_rack.third_party_settings.data_surface_resource_test.supplier' => 'supplier'],
    ], ['larder' => '[a-z]+']);
    $supplier = function () use ($route): array {
      $this->serveBuilt('data_surface_resource_test.larder.supplied', $route, ['larder' => 'pantry'], ['supplier' => 'Spice Co']);
      return $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['spare_rack']['third_party_settings']['data_surface_resource_test']['supplier'];
    };
    $this->setUpCurrentUser([], ['keep a larder', 'stock spice rack']);
    $this->assertSame('Spice Co', $supplier()['#default_value']);

    $this->container->get('data_surface.resources')->forgetBuilds();
    $this->setUpCurrentUser([], ['keep a larder', 'stock spice rack', 'inspect spice rack']);
    $element = $supplier();
    $this->assertTrue($element['#disabled']);
    $this->assertSame('Acme', $element['#default_value']);
  }

  /**
   * A key the build locks keeps what the build says.
   *
   * The larder's spare rack is its shelf's, which the larder's context
   * hands it: the shelf is locked in the rack's frame, so the link that
   * names another starts nothing there, and starts the larder's own note
   * and the other rack's label.
   */
  public function testLockedKeyKeepsWhatTheBuildSays(): void {
    $this->setUpCurrentUser([], ['keep a larder', 'stock spice rack']);
    $route = new Route('/surface-test/larder/{larder}/linked', [
      '_form' => DataSurfaceOperationForm::class,
      '_title' => 'Edit a larder',
      OperationRoute::RESOURCE => LarderResource::class,
      OperationRoute::OPERATION => 'update',
      OperationRoute::QUERY => ['note' => 'note', 'spare_rack.shelf' => 'shelf', 'rack.label' => 'label'],
    ], ['larder' => '[a-z]+']);
    $this->serveBuilt('data_surface_resource_test.larder.linked', $route, ['larder' => 'pantry'], [
      'note' => 'Restock soon',
      'shelf' => 'bottom',
      'label' => 'Herbs',
    ]);
    $surface = $this->form()[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Restock soon', $surface['note']['#default_value']);
    $this->assertSame('Herbs', $surface['rack']['label']['#default_value']);
    $this->assertTrue($surface['spare_rack']['shelf']['#disabled']);
    $this->assertSame('top', $surface['spare_rack']['shelf']['#default_value']);
  }

  /**
   * The code building the form may hand it values to start keys from.
   */
  public function testTheCodeBuildingTheFormStartsKeys(): void {
    $this->visit('/surface-test/card/add');
    $surface = $this->form([
      'title' => 'Handed',
      'copies' => 40,
      'occasion' => 'funeral',
      'envelope' => ['stamps' => '4'],
      'greeting' => ['value' => 'Dear you'],
    ])[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Handed', $surface['title']['#default_value']);
    // In the key's type, by the coercion table.
    $this->assertSame(4, $surface['envelope']['stamps']['#default_value']);
    $this->assertSame('Dear you', $surface['greeting']['value']['#default_value']);
    // Held to its key as a link's value is: one the key refuses starts
    // nothing, and a select never stands for a value it does not offer as
    // if it were stored.
    $this->assertSame(1, $surface['copies']['#default_value']);
    $this->assertEmpty($surface['occasion']['#default_value'] ?? NULL);
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::STALE_MARKER_KEY, $surface);

    // A link's value over the code's; what the route fixes over both.
    $this->visit('/surface-test/card/add-prefilled?subject=Pricing');
    $surface = $this->form(['title' => 'Handed', 'greeting' => ['format' => 'plain']])[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('Pricing', $surface['title']['#default_value']);
    $this->assertSame('html', $surface['greeting']['format']['#value']);
  }

  /**
   * A key no prefill reaches is the route or the code written wrong.
   *
   * Refused on the first draw, whether the link carries a value for it or
   * not, naming the route and the key.
   */
  public function testKeyNoPrefillReachesIsRefusedOnTheFirstDraw(): void {
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);
    foreach ([
      '/surface-test/card/query-secret' => 'The data_surface_route_test.query_secret route\'s "_data_surface_query": "code" on route_test.card is a secret, which no form draws, so no prefill starts it.',
      '/surface-test/card/query-undeclared?font=serif' => 'The data_surface_route_test.query_undeclared route\'s "_data_surface_query": route_test.card has no key "greeting.font". A prefill names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).',
      '/surface-test/card/query-inside' => 'The data_surface_route_test.query_inside route\'s "_data_surface_query": "paper_settings.weight" on route_test.card is inside "paper_settings", a key with variants, each starting from its own defaults, so a prefill does not reach it.',
      '/surface-test/card/query-map?greeting={"value":"Hi"}' => 'The data_surface_route_test.query_map route\'s "_data_surface_query": "greeting" on route_test.card holds a map: name each of its keys, as "greeting.<key>".',
      '/surface-test/card/six/rename-by-link?name=other' => 'The data_surface_route_test.edit_renamed_by_link route\'s "_data_surface_query": "card" on route_test.card is an identity key the context knows: identity is the operation\'s to say, from its parameters, so no prefill starts it.',
    ] as $path => $message) {
      $this->visit($path);
      $this->assertRefusedOnTheFirstDraw($message);
    }
    $this->serveBuilt('data_surface_resource_test.badge.queried', new Route('/surface-test/badge/queried', [
      '_form' => DataSurfaceOperationForm::class,
      '_title' => 'Configure the badge',
      OperationRoute::RESOURCE => BadgeResource::class,
      OperationRoute::OPERATION => 'configure',
      OperationRoute::QUERY => ['code' => 'code'],
    ]));
    $this->assertRefusedOnTheFirstDraw('The data_surface_resource_test.badge.queried route\'s "_data_surface_query": "code" on resource_test.badge is computed from first, so no prefill starts it.');

    // A link may start a key of an update the path names.
    $this->visit('/surface-test/card/six/edit-queried?subject=Renamed');
    $this->assertSame('Renamed', $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['title']['#default_value']);

    // The code's own values, the same way.
    $this->visit('/surface-test/card/add');
    $this->assertRefusedOnTheFirstDraw('The values handed to the form at the data_surface_route_test.add route: route_test.card has no key "nope". A prefill names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).', ['nope' => 1]);
    $this->assertRefusedOnTheFirstDraw('The values handed to the form at the data_surface_route_test.add route: "envelope" on route_test.card holds a structure: name each of its keys, as "envelope.<key>".', ['envelope' => 'cream']);
    $this->assertRefusedOnTheFirstDraw('The values handed to the form at the data_surface_route_test.add route: "greeting" on route_test.card holds a map: name each of its keys, as "greeting.<key>".', ['greeting' => '{"value":"Hi"}']);
  }

  /**
   * A submission's input stands for itself: the prefill is the first draw's.
   */
  public function testSubmissionLaysNoPrefill(): void {
    // The code hands the title, and the browser posts the form without it.
    $this->visit('/surface-test/card/add');
    $state = $this->post(['card' => 'one'], ['title' => 'Handed']);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('Hello', $state->getCompleteForm()[DataSurfaceOperationForm::SURFACE_KEY]['title']['#value'], 'An element left out takes the declared value, not the prefill.');
    $this->assertSame('Hello', \Drupal::state()->get(CardStorage::key('one'))['title']);

    // A select a link started, cleared by the visitor, stays cleared.
    $this->visit('/surface-test/card/add-prefilled?occasion=birthday&subject=Pricing');
    $this->assertSame('birthday', $this->form()[DataSurfaceOperationForm::SURFACE_KEY]['occasion']['#default_value']);
    $state = $this->post(['card' => 'two', 'title' => 'Posted', 'occasion' => '']);
    $this->assertSame([], $state->getErrors());
    $stored = \Drupal::state()->get(CardStorage::key('two'));
    $this->assertSame('Posted', $stored['title']);
    $this->assertEmpty($stored['occasion'] ?? NULL, 'Cleared, the select is not taken for the prefill.');
  }

}
