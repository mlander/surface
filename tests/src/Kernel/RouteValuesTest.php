<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\Declaring;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface_route_test\Storage\CardStorage;
use Drupal\Tests\surface\Kernel\Fixture\RefusedRoutes;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A route fixes or fills what the operation it serves asks for.
 *
 * The test module's card has a create served at three routes: one that
 * says nothing more, one that fixes three keys and fills three, and ones
 * whose values the card refuses. A fixed key is drawn as its value and
 * stated `const`, a filled one starts from its value, at every depth a
 * site default reaches: a key of the card's, a key inside the map it
 * declares, a key of the structure it attaches.
 *
 * A route's value may also come from its own path, by a reference, and a
 * value of the path the key refuses names nothing; a plain default pins
 * an operation parameter; and what a route may not say is refused as the
 * route's: a plain default nothing reads, a fixed identity key, a key the
 * operation narrows, values on a delete, one key fixed and filled, a
 * query map no link can use. A build holding what the request read is
 * never kept for the next one.
 *
 * @see docs/forms.md#a-route-that-fixes-or-fills-keys
 * @see docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RouteValuesTest extends SurfaceKernelTestBase {

  use OperationRouteTrait;
  use UserCreationTrait;

  /**
   * The card, by its id.
   */
  protected const CARD = 'route_test.card';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
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
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Puts one of the test module's routes on the request stack.
   *
   * @param string $name
   *   The route, less the module's prefix.
   * @param string|null $card
   *   The raw value of the card parameter, for a route that takes one.
   */
  protected function serveRoute(string $name, ?string $card = NULL): void {
    $name = 'surface_route_test.' . $name;
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    if ($card !== NULL) {
      $request->attributes->set('card', $card);
      $request->attributes->set('_raw_variables', new InputBag(['card' => $card]));
    }
    $stack->push($request);
  }

  /**
   * Puts one of the routes no routing file can carry on the stack.
   *
   * @param string $name
   *   The route, less the module's prefix (RefusedRoutes).
   */
  protected function serveRefused(string $name): void {
    $name = 'surface_route_test.' . $name;
    [$route, $raw] = RefusedRoutes::all()[$name];
    $this->serveBuilt($name, $route, $raw);
  }

  /**
   * Submits the generic form as the route on the stack.
   *
   * @param array $values
   *   Raw values by key.
   *
   * @return string[]
   *   The errors, by element name.
   */
  protected function submitForm(array $values): array {
    $form_state = new FormState();
    $form_state->setValues([SurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $form_state);
    return array_map(static fn ($error): string => PlainTextOutput::renderFromHtml((string) $error), $form_state->getErrors());
  }

  /**
   * Gets the full schema the operation the route on the stack serves states.
   *
   * @return array
   *   The schema.
   */
  protected function fullSchema(): array {
    $served = $this->served();
    return $this->container->get('surface.contract_emitter')
      ->emitFull($this->resources()->buildStatic($served->resource, $served->context), self::CARD, $served->operation->id)
      ->document['schema'];
  }

  /**
   * Gets the full schema of the card's key at a path, through its defs.
   *
   * @param array $schema
   *   The full schema.
   * @param string ...$path
   *   The keys, outermost first.
   *
   * @return array
   *   The key's schema.
   */
  protected static function property(array $schema, string ...$path): array {
    $at = $schema;
    foreach ($path as $key) {
      $at = $at['properties'][$key];
      if (isset($at['$ref'])) {
        $at = $schema['$defs'][substr($at['$ref'], strlen('#/$defs/'))];
      }
    }
    return $at;
  }

  /**
   * Submits values for the card's create through the pipeline.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The create's context, as the route made it.
   * @param array $values
   *   The values.
   *
   * @return array<string, string>
   *   The violations, by full path; empty when it was written.
   */
  protected function submitThroughPipeline(OperationContext $context, array $values): array {
    $surface = $this->resources()->build(self::CARD, $context);
    $result = $this->pipeline()->submit($surface, $values, $this->resources()->storage(self::CARD, $context, $surface));
    $violations = [];
    foreach ($result->violations as $violation) {
      $violations[$violation->fullPath()] = PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    return $violations;
  }

  /**
   * A fixed key is drawn as its value and stated `const`, at every depth.
   */
  public function testFixedKeysAreDrawnFixedAndStatedConst(): void {
    $this->serveRoute('add');
    $form = $this->surfaceForm();
    $this->assertSame('textfield', $form['title']['#type']);
    $this->assertSame('select', $form['envelope']['color']['#type']);

    $this->serveRoute('add_welcome');
    $form = $this->surfaceForm();
    // A key of the card's, a key of the map it declares, a key of the
    // structure it attaches.
    foreach ([
      'Welcome' => $form['title'],
      'html' => $form['greeting']['format'],
      'cream' => $form['envelope']['color'],
    ] as $value => $element) {
      $this->assertSame('item', $element['#type']);
      $this->assertSame($value, $element['#value']);
      $this->assertSame($value, $element['#plain_text']);
    }
    // The collection of envelopes beside it is the structure's own.
    $this->assertArrayNotHasKey('#value', $form['inserts'][0]['color'] ?? []);

    $schema = $this->fullSchema();
    $title = static::property($schema, 'title');
    $this->assertSame('Welcome', $title['const']);
    $this->assertSame('Welcome', $title['default']);
    $this->assertSame(['fixed' => 'Welcome'], $title['x-surface']);
    $this->assertSame('html', static::property($schema, 'greeting', 'format')['const']);
    $this->assertSame('cream', static::property($schema, 'envelope', 'color')['const']);
    // The structure where it is not fixed still offers both colors.
    $item = $schema['$defs'][substr($schema['properties']['inserts']['items']['$ref'], strlen('#/$defs/'))];
    $this->assertSame(['white', 'cream'], array_column($item['properties']['color']['oneOf'], 'const'));

    // The contract panel beside the form states what the form draws.
    $panel = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class)[SurfaceOperationForm::SURFACE_KEY][SurfaceOperationForm::PANEL_KEY];
    $this->assertStringContainsString('"const": "Welcome"', $panel['contract']['json']['#code']);
    $this->assertStringContainsString('"const": "cream"', $panel['full']['json']['#code']);
  }

  /**
   * A fixed key refuses another value at every door, and holds its own.
   */
  public function testFixedKeysHoldTheirValue(): void {
    $this->serveRoute('add_welcome');
    $errors = $this->submitForm(['card' => 'one', 'title' => 'Other', 'envelope' => ['color' => 'white']]);
    $this->assertArrayHasKey('surface][title', $errors);
    $this->assertArrayHasKey('surface][envelope][color', $errors);
    $this->assertNull(\Drupal::state()->get(CardStorage::key('one')));

    // Through the pipeline, as any door sends it, each refused by name.
    $context = $this->served()->context;
    $violations = $this->submitThroughPipeline($context, [
      'card' => 'two',
      'title' => 'Other',
      'greeting' => ['format' => 'plain'],
    ]);
    $this->assertSame(['title', 'greeting.format'], array_keys($violations));

    // Left as drawn, the fixed values are what storage receives.
    $this->assertSame([], $this->submitForm(['card' => 'three']));
    $stored = \Drupal::state()->get(CardStorage::key('three'));
    $this->assertSame('Welcome', $stored['title']);
    $this->assertSame('html', $stored['greeting']['format']);
    $this->assertSame('cream', $stored['envelope']['color']);
  }

  /**
   * A fixed key holds its value, a key that may otherwise hold nothing too.
   *
   * The card's occasion may be left out. Fixed, it starts from the fixed
   * value, is drawn as it and stated `const` and required, and a card
   * added at the page stores it, holds no other and cannot leave it empty.
   */
  public function testFixedOptionalKeyHoldsItsValue(): void {
    $this->serveRoute('add');
    $this->assertSame('select', $this->surfaceForm()['occasion']['#type']);
    $this->assertNotContains('occasion', $this->fullSchema()['required'] ?? []);

    $this->serveRoute('add_thanks');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class)[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('item', $form['occasion']['#type']);
    $this->assertSame('thanks', $form['occasion']['#value']);
    $this->assertSame('thanks', $form['occasion']['#plain_text']);
    // The contract panel beside the form states it so, in both documents.
    foreach (['contract', 'full'] as $document) {
      $schema = json_decode($form[SurfaceOperationForm::PANEL_KEY][$document]['json']['#code'], TRUE);
      $occasion = static::property($schema, 'occasion');
      $this->assertSame('thanks', $occasion['const'], $document);
      $this->assertSame('thanks', $occasion['default'], $document);
      $this->assertSame('thanks', $occasion['x-surface']['fixed'], $document);
      $this->assertContains('occasion', $schema['required'], $document);
    }

    // Left out, the fixed value is stored; another value is refused.
    $this->assertSame([], $this->submitForm(['card' => 'ten']));
    $this->assertSame('thanks', \Drupal::state()->get(CardStorage::key('ten'))['occasion']);
    $this->assertArrayHasKey('surface][occasion', $this->submitForm(['card' => 'eleven', 'occasion' => 'birthday']));
    $this->assertNull(\Drupal::state()->get(CardStorage::key('eleven')));
    // Through the pipeline, as any door sends it: left out, the fixed
    // value; emptied, refused as a key that must hold a value.
    $context = $this->served()->context;
    $this->assertSame([], $this->submitThroughPipeline($context, ['card' => 'twelve']));
    $this->assertSame('thanks', \Drupal::state()->get(CardStorage::key('twelve'))['occasion']);
    $emptied = $this->submitThroughPipeline($context, ['card' => 'thirteen', 'occasion' => NULL]);
    $this->assertSame(['occasion'], array_keys($emptied));
  }

  /**
   * A filled key starts from the route's value, and is stored if left.
   */
  public function testDefaultsAreWhatKeysStartFrom(): void {
    $this->serveRoute('add_welcome');
    $form = $this->surfaceForm();
    $this->assertSame('Start here.', $form['greeting']['value']['#default_value']);
    // Written as text in the routing file, read by the coercion table.
    $this->assertSame(3, $form['copies']['#default_value']);
    $this->assertSame(2, $form['envelope']['stamps']['#default_value']);

    $schema = $this->fullSchema();
    $this->assertSame('Start here.', static::property($schema, 'greeting', 'value')['default']);
    $this->assertSame(3, static::property($schema, 'copies')['default']);
    $this->assertSame(2, static::property($schema, 'envelope', 'stamps')['default']);

    // A create that leaves them stores them, through the pipeline as
    // through the form.
    $this->assertSame([], $this->submitThroughPipeline($this->served()->context, ['card' => 'four']));
    $stored = \Drupal::state()->get(CardStorage::key('four'));
    $this->assertSame('Start here.', $stored['greeting']['value']);
    $this->assertSame(3, $stored['copies']);
    $this->assertSame(2, $stored['envelope']['stamps']);
    $this->assertSame([], $this->submitForm(['card' => 'five', 'copies' => 4]));
    $stored = \Drupal::state()->get(CardStorage::key('five'));
    $this->assertSame(4, $stored['copies']);
    $this->assertSame('Start here.', $stored['greeting']['value']);
  }

  /**
   * Defaults on an operation that does not create are refused by name.
   */
  public function testDefaultsOnAnUpdateAreRefused(): void {
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored', 'copies' => 5]);
    $this->serveRoute('edit_refilled', 'six');
    $this->assertRouteRefused('The surface_route_test.edit_refilled route: The "update" operation on route_test.card does not create, and carries defaults for "copies": a default is what a new thing starts from, and on an update the stored value stands.');

    // The same said by an operation's own context, at the build.
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "update" operation on route_test.card does not create, and carries defaults for "copies"');
    $this->resources()->build(self::CARD, $this->resources()->operation(self::CARD, 'update', ['card' => 'six'])->withDefaults(['copies' => 2]));
  }

  /**
   * A value the key refuses, or a path that names no key, is refused.
   */
  public function testRefusedValuesNameTheKey(): void {
    $this->serveRoute('add_too_many_stamps');
    $this->assertRouteRefused('The surface_route_test.add_too_many_stamps route: The "create" operation sets "envelope.stamps" on route_test.card to 9, which the key does not accept: This value should be between 1 and 5.');

    $this->serveRoute('add_tucked');
    $this->assertRouteRefused('The surface_route_test.add_tucked route: The "create" operation: route_test.card has no key "envelope.flap". A fixed value names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).');

    $create = $this->resources()->operation(self::CARD, 'create');
    $this->assertRefused(
      'The "create" operation fixes "envelope.color" on route_test.card to "red", which the key does not accept: The value you selected is not a valid choice.',
      $create->withConstraint('envelope.color', 'Choice', ['choices' => ['red'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation sets "title" on route_test.card to "This title is longer than forty characters by far", which the key does not accept: This value is too long. It should have 40 characters or less.',
      $create->withDefaults(['title' => 'This title is longer than forty characters by far']),
    );
    // A list for a key that holds one value, refused before the key's
    // length is read, which would throw for it.
    $this->assertRefused(
      'The "create" operation sets "title" on route_test.card to ["Hello","again"], which the key does not accept: it holds one string, not a list or a map.',
      $create->withDefaults(['title' => ['Hello', 'again']]),
    );
    $this->assertRefused(
      'The "create" operation: route_test.card has no key "greeting.font". A default names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).',
      $create->withDefaults(['greeting.font' => 'serif']),
    );
  }

  /**
   * What a default or a fixed value does not reach is refused, saying why.
   */
  public function testPathsIntoWhatNoValueReachesAreRefused(): void {
    $create = $this->resources()->operation(self::CARD, 'create');
    $this->assertRefused(
      'The "create" operation: "paper_settings.weight" on route_test.card is inside "paper_settings", a key with variants, each starting from its own defaults, so a default does not reach it.',
      $create->withDefaults(['paper_settings.weight' => 80]),
    );
    $this->assertRefused(
      'The "create" operation: "inserts.color" on route_test.card is inside "inserts", a collection, each item starting from its structure\'s defaults, so a fixed value does not reach it.',
      $create->withConstraint('inserts.color', 'Choice', ['choices' => ['cream'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation: "code" on route_test.card is a secret: both documents state a fixed value as `const`, and a secret is never read back, so it cannot be fixed.',
      $create->withConstraint('code', 'Choice', ['choices' => ['1234'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation: "code" on route_test.card is a secret: both documents state a default, and a secret is never read back, so it takes none.',
      $create->withDefaults(['code' => '1234']),
    );
    $this->assertRefused(
      'The "create" operation: "greeting" on route_test.card holds a map, and a fixed value is one value of a key that holds one: fix each key inside it by its path.',
      $create->withConstraint('greeting', 'Choice', ['choices' => ['html'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation: "envelope" on route_test.card holds a structure: set each of its keys, as "envelope.<key>".',
      $create->withDefaults(['envelope' => '{}']),
    );
  }

  /**
   * Asserts a context is refused when the card is built in it.
   *
   * @param string $message
   *   The refusal.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   */
  protected function assertRefused(string $message, OperationContext $context): void {
    try {
      $this->resources()->build(self::CARD, $context);
      $this->fail(sprintf('Not refused: %s', $message));
    }
    catch (\LogicException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * The declared default, the site's, then the route's, each over the last.
   */
  public function testTheRoutesDefaultIsLast(): void {
    $built = fn (?OperationContext $context = NULL): mixed => $this->resources()->build(self::CARD, $context ?? $this->resources()->operation(self::CARD, 'create'))->getDefault('copies');
    $this->assertSame(1, $built());
    $this->config('surface.defaults')->set('defaults', [
      ['resource' => self::CARD, 'key' => 'copies', 'value' => '4'],
      ['resource' => self::CARD, 'key' => 'envelope.stamps', 'value' => '4'],
    ])->save();
    $this->assertSame(4, $built());
    $this->serveRoute('add_welcome');
    $served = $this->served();
    $this->assertSame(3, $this->resources()->build(self::CARD, $served->context)->getDefault('copies'));
    $this->assertSame(['color' => 'cream', 'stamps' => 2], $this->resources()->build(self::CARD, $served->context)->getDefault('envelope'));
    // A key the route leaves is the site's.
    $this->assertSame(4, $this->resources()->build(self::CARD, $this->resources()->operation(self::CARD, 'create'))->getDefault('envelope')['stamps']);
  }

  /**
   * A context's defaults are kept by every copy and win over earlier ones.
   */
  public function testContextDefaults(): void {
    $context = (new OperationContext('create', creates: TRUE))
      ->withDefaults(['copies' => 2, 'title' => 'Hi'])
      ->withDefaults(['copies' => 3]);
    $this->assertSame(['copies' => 3, 'title' => 'Hi'], $context->defaults());
    foreach ([
      $context->withConstraint('copies', 'Range', ['min' => 2]),
      $context->withKnown(['card' => 'seven']),
      $context->withId('create'),
      $context->withChild('envelope', operation: 'create'),
      $context->stamped('create', TRUE),
    ] as $copy) {
      $this->assertSame(['copies' => 3, 'title' => 'Hi'], $copy->defaults());
    }
    $this->assertSame([], (new OperationContext())->defaults());
    $this->expectException(\InvalidArgumentException::class);
    $context->withDefaults(['' => 1]);
  }

  /**
   * A reference fixes a key to the path's value, drawn and stated so.
   */
  public function testReferenceFixesTheKeyToThePathsValue(): void {
    $this->visit('/surface-test/card/add/cream');
    $form = $this->surfaceForm();
    $this->assertSame('item', $form['envelope']['color']['#type']);
    $this->assertSame('cream', $form['envelope']['color']['#value']);
    $color = static::property($this->fullSchema(), 'envelope', 'color');
    $this->assertSame('cream', $color['const']);
    $this->assertSame('cream', $color['default']);

    // Another value of the path, another page.
    $this->visit('/surface-test/card/add/white');
    $this->assertSame('white', static::property($this->fullSchema(), 'envelope', 'color')['const']);
  }

  /**
   * A reference fills a key from the path, read by the coercion table.
   */
  public function testReferenceFillsTheKeyFromThePath(): void {
    $this->visit('/surface-test/card/add-copies/4');
    $this->assertSame(4, $this->surfaceForm()['copies']['#default_value']);
    $this->assertSame(4, static::property($this->fullSchema(), 'copies')['default']);
    $this->assertSame([], $this->submitThroughPipeline($this->served()->context, ['card' => 'eight']));
    $this->assertSame(4, \Drupal::state()->get(CardStorage::key('eight'))['copies']);
  }

  /**
   * An optional placeholder the URL leaves out seeds nothing.
   */
  public function testAbsentOptionalReferenceSeedsNothing(): void {
    $this->visit('/surface-test/card/add-optional');
    $served = $this->served();
    $this->assertSame([], $served->context->constraints());
    $this->assertSame('select', $this->surfaceForm()['envelope']['color']['#type']);

    $this->visit('/surface-test/card/add-optional/cream');
    $this->assertSame('cream', $this->surfaceForm()['envelope']['color']['#value']);
  }

  /**
   * A value of the path the key refuses names nothing: the page is not found.
   */
  public function testPathValueTheKeyRefusesNamesNothing(): void {
    $message = 'The surface_route_test.add_colored route reads "{color}" from its path, and the value names nothing here: The "create" operation fixes "envelope.color" on route_test.card to "red", which the key does not accept: The value you selected is not a valid choice.';
    $this->visit('/surface-test/card/add/red');
    try {
      $this->served();
      $this->fail('A color the envelope does not come in was served.');
    }
    catch (NamesNothingException $e) {
      $this->assertSame($message, $e->getMessage());
      $this->assertSame('color', $e->parameter);
    }
    // The form's page is not found.
    try {
      $this->surfaceForm();
      $this->fail('The form was built.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * A reference the route cannot read, or puts where no key is, is the route's.
   */
  public function testReferenceWrittenWrongIsTheRoutes(): void {
    $this->serveRefused('add_misspelled');
    $this->assertRouteRefused('The surface_route_test.add_misspelled route fixes "envelope.color" to "{hue}", which names no variable of its path /surface-test/card/add-misspelled/{color}: a reference reads a value of the route\'s own path.');

    // Where it puts the value is wrong whatever the value, so a value
    // the key would refuse is no page not found either.
    $refusal = 'The surface_route_test.add_misplaced route: The "create" operation: route_test.card has no key "envelope.hue". A fixed value names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).';
    foreach (['cream', 'red'] as $color) {
      $this->visit('/surface-test/card/add-misplaced/' . $color);
      $this->assertRouteRefused($refusal);
    }
  }

  /**
   * A plain default is the operation's parameter; the path's is the subject.
   */
  public function testPlainDefaultPinsTheOperationsParameter(): void {
    $this->visit('/surface-test/card/send-friends');
    $served = $this->served();
    $this->assertSame('send', $served->operation->id);
    $this->assertSame(['to' => 'friends'], $served->context->known);
    // A pin is no subject: the page is about no one thing.
    $this->assertNull($served->subject);
    $this->assertSame('Dear friends', $this->surfaceForm()['title']['#default_value']);

    // The path's value is the subject, though the pin is the first
    // parameter.
    $this->visit('/surface-test/card/send-from/grandma');
    $served = $this->served();
    $this->assertSame(['to' => 'friends', 'from' => 'grandma'], $served->context->known);
    $this->assertSame('grandma', $served->subject);

    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);
    $this->visit('/surface-test/card/six/in-cream');
    $this->assertSame('six', $this->served()->subject);
  }

  /**
   * A plain default nothing the route serves reads is refused, naming it.
   */
  public function testPlainDefaultNothingReadsIsRefused(): void {
    $this->visit('/surface-test/card/send-misnamed');
    $this->assertRouteRefused('The surface_route_test.send_misnamed route has the plain default "too", which nothing it serves reads: the "send" operation takes $to, $from. A default with no leading underscore is a route parameter, which the operation reads by its name.');
  }

  /**
   * An identity key the context knows is the operation's alone to say.
   */
  public function testKnownIdentityKeyIsNeitherFixedNorFilled(): void {
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);
    $this->visit('/surface-test/card/six/rename');
    $this->assertRouteRefused('The surface_route_test.edit_renamed route: The "update" operation fixes "card" on route_test.card, an identity key the context knows: identity is the operation\'s to say, from its parameters, so neither a route nor a parent may fix or fill it.');

    // The same refusal for a context any parent hands, at the build.
    $update = $this->resources()->operation(self::CARD, 'update', ['card' => 'six']);
    $this->assertRefused(
      'The "update" operation fixes "card" on route_test.card, an identity key the context knows: identity is the operation\'s to say, from its parameters, so neither a route nor a parent may fix or fill it.',
      $update->withConstraint('card', 'Choice', ['choices' => ['other'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation sets "card" on route_test.card, an identity key the context knows: identity is the operation\'s to say, from its parameters, so neither a route nor a parent may fix or fill it.',
      (new OperationContext('create', creates: TRUE, known: ['card' => 'seven']))->withDefaults(['card' => 'eight']),
    );
  }

  /**
   * A key the operation narrows with a Choice is the operation's to fix.
   */
  public function testRouteMayNotFixWhatTheOperationNarrows(): void {
    $this->visit('/surface-test/card/send-cream');
    $this->assertRouteRefused('The surface_route_test.send_cream route fixes "envelope.color", which the "send" operation already narrows with a Choice of its own: the route\'s would take its place. A key the operation narrows to a list is the operation\'s to fix.');
  }

  /**
   * A delete takes no values, so a route says nothing of its keys.
   *
   * Neither a seed nor a key a link may start: the delete page draws no
   * key to start.
   */
  public function testDeleteTakesNoRouteValues(): void {
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);
    $this->visit('/surface-test/card/six/delete');
    $this->assertRouteRefused('The surface_route_test.delete_titled route carries "_surface_fixed", and the "delete" operation it serves deletes: a delete takes no values, so there is no key to fix, fill or start.');

    $queried = clone $this->container->get('router.route_provider')->getRouteByName('surface_route_test.delete_titled');
    $queried->setDefaults([OperationRoute::QUERY => ['title' => 'title']] + array_diff_key($queried->getDefaults(), [OperationRoute::FIXED => TRUE]));
    $this->serveBuilt('surface_route_test.delete_queried', $queried, ['card' => 'six']);
    $this->assertRouteRefused('The surface_route_test.delete_queried route carries "_surface_query", and the "delete" operation it serves deletes: a delete takes no values, so there is no key to fix, fill or start.');
  }

  /**
   * One key is fixed or filled, not both.
   */
  public function testOneKeyIsFixedOrFilled(): void {
    $this->serveRefused('add_twice');
    $this->assertRouteRefused('The surface_route_test.add_twice route both fixes and fills "title": a fixed value is also what its key starts from, so name it under "_surface_fixed" alone.');
  }

  /**
   * A route lists the keys a link may start, refusing names no link can use.
   */
  public function testTheQueryMapNamesWhatLinksMayStart(): void {
    $this->visit('/surface-test/card/add-subject');
    $this->assertSame(['title' => 'subject', 'greeting.value' => 'greeting'], $this->served()->query);
    // Read off the route, never the context.
    $this->assertSame([], $this->served()->context->defaults());

    foreach ([
      'query_braced' => 'The surface_route_test.query_braced route starts "title" from the query parameter "{subject}": a query name is a plain name, never a reference, since what a link sends is input. Name another query parameter.',
      'query_empty' => 'The surface_route_test.query_empty route\'s "_surface_query" is a map of key paths to query names, as { subject: subject }.',
      'query_reserved' => 'The surface_route_test.query_reserved route starts "title" from the query parameter "destination": Drupal or this module reads that parameter for itself on a form page. Name another query parameter.',
      'query_variable' => 'The surface_route_test.query_variable route starts "title" from the query parameter "color": that is a variable of the route\'s path too, and a link built for the route leaves it out of the query. Name another query parameter.',
      'query_default' => 'The surface_route_test.query_default route starts "title" from the query parameter "to": that is a default of the route too, and a link built for the route leaves it out of the query. Name another query parameter.',
    ] as $route => $refusal) {
      // The router's rebuild refuses each, so each is matched by hand.
      $this->serveRefused($route);
      $this->assertRouteRefused($refusal);
    }
  }

  /**
   * A build holding what the request read is never kept for the next one.
   *
   * A route's held build is kept in the build cache when its context is the
   * same on every request: the literal values, a parameter a plain default
   * pins. A value of the path, handed to the operation or read by a
   * reference, would be one more entry per value a URL carries.
   */
  public function testWhatThePathSuppliedIsNeverKept(): void {
    $bin = $this->container->get('cache.surface_build');
    $kept = function () use ($bin): bool {
      $served = $this->served();
      $resources = $this->resources();
      assert($resources instanceof Resources);
      $key = (new \ReflectionMethod(Resources::class, 'memoKey'))->invoke($resources, $served->resource, $served->context, TRUE, TRUE);
      $cid = (new \ReflectionMethod(Resources::class, 'cacheId'))->invoke($resources, $served->resource, $key);
      $this->assertIsString($cid);
      return $bin->get($cid) !== FALSE;
    };
    $bin->deleteAll();
    \Drupal::state()->set(CardStorage::key('six'), ['card' => 'six', 'title' => 'Stored']);

    $this->serveRoute('add_welcome');
    $this->assertTrue($kept(), 'A literal create route is kept.');
    $this->visit('/surface-test/card/send-friends');
    $this->assertTrue($kept(), 'A plain default is the same on every request, and kept.');
    $this->visit('/surface-test/card/add/cream');
    $this->assertFalse($kept(), 'A reference read from the path is not kept.');
    $this->visit('/surface-test/card/six/in-cream');
    $this->assertFalse($kept(), 'A route handing the operation a value of its path is not kept.');
  }

  /**
   * A held build is the request's: asked again, the same, and never kept.
   */
  public function testHeldBuildIsTheRequestsAlone(): void {
    $resources = $this->resources();
    assert($resources instanceof Resources);
    $bin = $this->container->get('cache.surface_build');
    $bin->deleteAll();
    $context = $resources->operation(self::CARD, 'update', ['card' => 'nine'])
      ->withConstraint('envelope.color', 'Choice', ['choices' => ['cream'], Declaring::FIXED => TRUE]);
    $held = $resources->holdDeclared(self::CARD, $context);
    $counts = $resources->buildCounts();
    $this->assertSame($held, $resources->holdDeclared(self::CARD, $context));
    $after = $resources->buildCounts();
    $this->assertSame($counts['built'], $after['built']);
    $this->assertSame($counts['reused'] + 1, $after['reused']);
    $this->assertSame($counts['cached'], $after['cached'], 'The memo answered: the bin was not read.');
    $key = (new \ReflectionMethod(Resources::class, 'memoKey'))->invoke($resources, self::CARD, $context, TRUE, TRUE);
    $this->assertFalse($bin->get((new \ReflectionMethod(Resources::class, 'cacheId'))->invoke($resources, self::CARD, $key)));
    // The same build, declared, is kept.
    $resources->forgetBuilds();
    $resources->buildDeclared(self::CARD, $context);
    $this->assertNotFalse($bin->get((new \ReflectionMethod(Resources::class, 'cacheId'))->invoke($resources, self::CARD, $key)));
  }

}
