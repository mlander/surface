<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\Declaring;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_route_test\Storage\CardStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

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
 * @see docs/forms.md#a-route-that-fixes-or-fills-keys
 * @see docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RouteValuesTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
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
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
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
    $name = 'data_surface_route_test.' . $name;
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
   * Reads the operation the route on the stack serves.
   *
   * @return \Drupal\data_surface\ResourceBuild\OperationRoute
   *   The operation and its context.
   */
  protected function served(): OperationRoute {
    return OperationRoute::fromRouteMatch(
      $this->container->get('current_route_match'),
      $this->container->get('data_surface.resource_registry'),
      $this->container->get('data_surface.operation_arguments'),
      $this->resources(),
    );
  }

  /**
   * Builds the generic form as the route on the stack.
   *
   * @return array
   *   The built form's data surface container.
   */
  protected function surfaceForm(): array {
    return $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
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
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
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
    return $this->container->get('data_surface.contract_emitter')
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
   * @param \Drupal\data_surface\Resource\OperationContext $context
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
   * Asserts the route on the stack is refused, saying why.
   *
   * @param string $message
   *   The refusal.
   */
  protected function assertRouteRefused(string $message): void {
    try {
      $this->served();
      $this->fail('The route was not refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame($message, $e->getMessage());
    }
    // The access check answers it as it answers a parameter that names
    // nothing: a refusal saying why, never an error.
    $access = $this->container->get('data_surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'));
    $this->assertTrue($access->isForbidden());
    $this->assertSame($message, $access->getReason());
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
    $panel = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY][DataSurfaceOperationForm::PANEL_KEY];
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
    $this->assertRouteRefused('The data_surface_route_test.edit_refilled route: The "update" operation on route_test.card does not create, and carries defaults for "copies": a default is what a new thing starts from, and on an update the stored value stands.');

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
    $this->assertRouteRefused('The data_surface_route_test.add_too_many_stamps route: The "create" operation sets "envelope.stamps" on route_test.card to 9, which the key does not accept: This value should be between 1 and 5.');

    $this->serveRoute('add_tucked');
    $this->assertRouteRefused('The data_surface_route_test.add_tucked route: The "create" operation: route_test.card has no key "envelope.flap". A fixed value names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).');

    $create = $this->resources()->operation(self::CARD, 'create');
    $this->assertRefused(
      'The "create" operation fixes "envelope.color" on route_test.card to "red", which the key does not accept: The value you selected is not a valid choice.',
      $create->withConstraint('envelope.color', 'Choice', ['choices' => ['red'], Declaring::FIXED => TRUE]),
    );
    $this->assertRefused(
      'The "create" operation sets "title" on route_test.card to "This title is longer than forty characters by far", which the key does not accept: This value is too long. It should have 40 characters or less.',
      $create->withDefaults(['title' => 'This title is longer than forty characters by far']),
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
   * @param \Drupal\data_surface\Resource\OperationContext $context
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
    $this->config('data_surface.defaults')->set('defaults', [
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

}
