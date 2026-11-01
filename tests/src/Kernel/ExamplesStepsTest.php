<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface_examples\CodeLines;
use Drupal\surface_examples\ExamplePanels;
use Drupal\surface_examples\Form\RegistrationStep1ClassicForm;
use Drupal\surface_examples\Form\RegistrationStep2ClassicForm;
use Drupal\surface_examples\Resource\RegistrationStep1Resource;
use Drupal\surface_examples\Resource\RegistrationStep2Resource;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface\Form\ContractPanel;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the examples' steps 1 to 3, and the contract panel under them.
 *
 * Each step's route is served by the generic operation form, so what is
 * asserted is what a person sees: the form builds from the resource, a
 * refinement narrows what the next key offers, the variants resolve to the
 * chosen ticket, the contact part validates in its own frame, and the
 * panel says what each key allows as the answers stand.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesStepsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Puts one of the examples' routes on the request stack.
   *
   * @param string $route_name
   *   The route.
   * @param string $method
   *   The request method; POST for a rebuild.
   */
  protected function serveRoute(string $route_name, string $method = 'GET'): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), $method);
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Submits the generic form at the route on the stack.
   *
   * @param array $values
   *   Raw values keyed by resource key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([SurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Builds a step's resource in its configure operation.
   *
   * @param class-string $class
   *   The resource class.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function surface(string $class): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build($class, $resources->operation($class, 'configure'));
  }

  /**
   * Builds the contract panel's rows for a resource and values.
   *
   * @param class-string $class
   *   The resource class.
   * @param array $values
   *   The values the form would be built with.
   *
   * @return array<string, array>
   *   The rows' cells, keyed by the key's path.
   */
  protected function panelRows(string $class, array $values): array {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation($class, 'configure');
    $served = new OperationRoute(
      $class,
      $this->container->get('surface.resource_registry')->getOperation($class, 'configure'),
      $context,
      NULL,
    );
    $panel = $this->container->get('surface.contract_panel')
      ->buildPanel($served, $resources->build($class, $context), $values);
    $rows = [];
    foreach ($panel['keys']['#rows'] as $row) {
      $rows[$row['surface-key']] = array_map(
        static fn (mixed $cell): string => is_array($cell) ? strip_tags((string) $cell['data']['#markup']) : (string) $cell,
        $row['data'],
      );
    }
    return $rows;
  }

  /**
   * Tests step 1: the form builds and saves, as its classic twin does.
   */
  public function testStepOneSavesWhatTheClassicFormSaves(): void {
    $this->serveRoute('surface_examples.step1');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('textfield', $container['title']['#type']);
    $this->assertSame('Spring meetup', $container['title']['#default_value']);
    $this->assertSame(1000, $container['capacity']['#max']);
    $this->assertSame('checkbox', $container['open']['#type']);
    $this->assertArrayHasKey(SurfaceOperationForm::PANEL_KEY, $container);

    // A checkbox left clear is submitted as nothing at all.
    $state = $this->submitTheForm(['title' => 'Summer meetup', 'capacity' => '200', 'open' => NULL]);
    $this->assertSame([], $state->getErrors());
    $config = $this->config('surface_examples.registration_step1');
    $this->assertSame(['title' => 'Summer meetup', 'capacity' => 200, 'open' => FALSE], array_diff_key($config->get(), ['_core' => TRUE]));

    $refused = $this->submitTheForm(['title' => 'Summer meetup', 'capacity' => '1001', 'open' => '1']);
    $this->assertArrayHasKey('surface][capacity', $refused->getErrors());

    // The classic twin writes the same object the same way.
    $classic = new FormState();
    $classic->setValues(['title' => 'Classic meetup', 'capacity' => '300', 'open' => 1]);
    $this->container->get('form_builder')->submitForm(RegistrationStep1ClassicForm::class, $classic);
    $this->assertSame([], $classic->getErrors());
    $config = $this->config('surface_examples.registration_step1');
    $this->assertSame(['title' => 'Classic meetup', 'capacity' => 300, 'open' => TRUE], array_diff_key($config->get(), ['_core' => TRUE]));
  }

  /**
   * Tests step 2: the venue narrows the room, and the room the capacity.
   */
  public function testStepTwoNarrowsRoomByVenueAndCapacityByRoom(): void {
    $surface = $this->surface(RegistrationStep2Resource::class);
    $this->assertSame(['venue'], $surface->getDefinitions()->dependencies('room'));
    $this->assertSame(['room'], $surface->getDefinitions()->dependencies('capacity'));

    $rooms = $this->options()->resolve($surface->refine(['venue' => 'harbour'])->getDefinition('room'));
    $this->assertSame(['harbour_auditorium', 'harbour_deck'], array_keys($rooms->options));
    $this->assertSame(['title', 'open', 'venue', 'room', 'capacity'], $surface->getDefinitions()->names());
    $this->assertNull($surface->getDefinition('capacity')->getDescription());
    $capacity = $surface->refine(['venue' => 'library', 'room' => 'library_garden'])->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 30], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 30 for the Garden room.', (string) $capacity->getDescription());

    $paths = fn (array $values): array => array_map(
      static fn ($violation): string => $violation->fullPath(),
      iterator_to_array($this->pipeline()->validate($surface, $values + ['title' => 'Meetup', 'venue' => 'library'])),
    );
    $this->assertSame(['capacity'], $paths(['room' => 'library_garden', 'capacity' => 45]));
    $this->assertSame(['room'], $paths(['room' => 'harbour_deck', 'capacity' => 45]));

    // The form offers the stored venue's rooms, and rebuilds on both.
    $this->serveRoute('surface_examples.step2');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame(['riverside_main', 'riverside_east'], array_keys(array_diff_key($container['room']['#options'], ['' => TRUE])));
    $this->assertArrayHasKey('#ajax', $container['venue']);
    $this->assertArrayHasKey('#ajax', $container['room']);
    // A select changes while focused, and keeps core's refocus.
    $this->assertArrayNotHasKey('disable-refocus', $container['venue']['#ajax']);
    $this->assertSame(400, $container['capacity']['#max']);
    $this->assertSame('Up to 400 for the Main hall.', (string) $container['capacity']['#description']);
    // Declaration order is form order: the capacity follows the room it
    // depends on.
    $keys = ['title', 'open', 'venue', 'room', 'capacity'];
    $this->assertSame($keys, array_values(array_intersect(Element::children($container), $keys)));

    // A submission is held to the room the form was built for: a browser
    // changing the venue rebuilds the form over AJAX first.
    $garden = ['title' => 'Meetup', 'venue' => 'library', 'room' => 'library_garden', 'open' => '1'];
    $state = $this->submitTheForm(['capacity' => '31'] + $garden);
    $this->assertArrayHasKey('surface][capacity', $state->getErrors());
    $state = $this->submitTheForm(['capacity' => '30'] + $garden);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('library_garden', $this->config('surface_examples.registration_step2')->get('room'));
  }

  /**
   * Tests step 2's classic twin refuses in the words the resource uses.
   *
   * The same answers asked of the resource and submitted to the twin: each
   * refusal on the same key, as the same sentence, and a valid set stored
   * by the twin as the resource would store it. A room of another venue is
   * not among them: submitted here, the twin is built for the venue
   * submitted, so core's choice check refuses it before validateForm()
   * is asked; ExamplesStep2ClassicTest posts it as a browser does.
   */
  public function testStepTwosClassicTwinRefusesInTheResourcesWords(): void {
    $surface = $this->surface(RegistrationStep2Resource::class);
    $cases = [
      'more than the room seats' => ['venue' => 'library', 'room' => 'library_garden', 'capacity' => 31],
      'fewer than one' => ['venue' => 'harbour', 'room' => 'harbour_deck', 'capacity' => 0],
    ];
    foreach ($cases as $case => $values) {
      $values += ['title' => 'Twin meetup', 'open' => TRUE];
      $resource = [];
      foreach ($this->pipeline()->validate($surface, $values) as $violation) {
        $resource[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      $classic = new FormState();
      $classic->setValues(['capacity' => (string) $values['capacity'], 'open' => 1] + $values);
      $this->container->get('form_builder')->submitForm(RegistrationStep2ClassicForm::class, $classic);
      $this->assertNotSame([], $resource, $case);
      $this->assertSame($resource, array_map(static fn ($message): string => strip_tags((string) $message), $classic->getErrors()), $case);
    }
    // Nothing written: read from storage, since the parent's validateForm()
    // copies what it validates onto the factory's editable object.
    $this->assertSame('riverside_main', $this->container->get('config.storage')->read('surface_examples.registration_step2')['room']);

    $classic = new FormState();
    $garden = ['title' => 'Twin meetup', 'venue' => 'library', 'room' => 'library_garden'];
    $classic->setValues(['open' => NULL, 'capacity' => '30'] + $garden);
    $this->container->get('form_builder')->submitForm(RegistrationStep2ClassicForm::class, $classic);
    $this->assertSame([], $classic->getErrors());
    $config = $this->config('surface_examples.registration_step2');
    $this->assertSame(['title' => 'Twin meetup', 'open' => FALSE] + $garden + ['capacity' => 30], array_diff_key($config->get(), ['_core' => TRUE]));
  }

  /**
   * Tests step 3: the pricing chooses the ticket variant; the contact part.
   */
  public function testStepThreeResolvesTheTicketAndValidatesTheContact(): void {
    $surface = $this->surface(RegistrationStep3Resource::class);
    $entry = $surface->getDefinitions()->entry('ticket');
    $this->assertSame('pricing', $entry->variants?->by);
    $this->assertSame(['free', 'paid'], $entry->variants->variantIds());
    $paid = $surface->refine(['pricing' => 'paid'])->getDefinition('ticket');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $paid);
    $this->assertSame(['price', 'currency'], array_keys($paid->getPropertyDefinitions()));
    $free = $surface->refine(['pricing' => 'free'])->getDefinition('ticket');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $free);
    $this->assertSame(['note'], array_keys($free->getPropertyDefinitions()));

    $base = [
      'title' => 'Meetup',
      'venue' => 'library',
      'room' => 'library_reading',
      'capacity' => 40,
      'contact' => ['email' => 'events@example.com'],
    ];
    $paths = fn (array $values): array => array_map(
      static fn ($violation): string => $violation->fullPath(),
      iterator_to_array($this->pipeline()->validate($surface, $values)),
    );
    $this->assertSame([], $paths($base + ['pricing' => 'paid', 'ticket' => ['price' => 12.5, 'currency' => 'EUR']]));
    $zero_price = ['pricing' => 'paid', 'ticket' => ['price' => 0.0, 'currency' => 'EUR']];
    $this->assertSame(['ticket.price'], $paths($base + $zero_price));
    $this->assertSame(['ticket.price'], $paths($base + ['pricing' => 'paid', 'ticket' => ['currency' => 'EUR']]));
    $this->assertSame(['contact.email'], $paths(['contact' => ['email' => 'not an address']] + $base + ['pricing' => 'free']));
    $this->assertSame(['contact.email'], $paths(['contact' => ['phone' => '555']] + $base + ['pricing' => 'free']));

    // Through the form, once paid is what is stored: the form renders the
    // paid ticket, and stores what is submitted for it.
    $this->config('surface_examples.registration_step3')
      ->set('pricing', 'paid')
      ->set('ticket', ['price' => 5.0, 'currency' => 'EUR'])
      ->save();
    $this->serveRoute('surface_examples.step3');
    $state = $this->submitTheForm([
      'title' => 'Meetup',
      'venue' => 'library',
      'room' => 'library_reading',
      'capacity' => '40',
      'open' => '1',
      'pricing' => 'paid',
      'ticket' => ['price' => '9.5', 'currency' => 'GBP'],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
    ]);
    $this->assertSame([], $state->getErrors());
    $config = $this->config('surface_examples.registration_step3');
    $this->assertEquals(['price' => 9.5, 'currency' => 'GBP'], $config->get('ticket'));
    $this->assertSame('paid', $config->get('pricing'));
  }

  /**
   * Tests the panel says what each key allows as the answers stand.
   */
  public function testThePanelFollowsTheAnswers(): void {
    $stored = $this->config('surface_examples.registration_step3')->getRawData();
    $rows = $this->panelRows(RegistrationStep3Resource::class, $stored);
    $this->assertSame([
      'title',
      'open',
      'venue',
      'room',
      'capacity',
      'pricing',
      'ticket',
      'ticket.note',
      'contact',
      'contact.email',
      'contact.phone',
    ], array_keys($rows));
    // Key, type, label, required, default, allows, depends on, right now.
    $this->assertSame(['room', 'string', 'Room', 'yes', '—', 'one of Main hall, East room', 'venue', 'narrowed'], $rows['room']);
    $this->assertSame(['capacity', 'integer', 'Capacity', 'no', '50', 'from 1 to 400', 'room', 'narrowed'], $rows['capacity']);
    $this->assertSame('as declared', $rows['title'][7]);
    $this->assertSame('the free variant', $rows['ticket'][7]);
    // Each variant by what it is called, its id after it; the contact,
    // which has no label, by its id.
    $this->assertSame('A part chosen by pricing: free → Free ticket (registration.step3.ticket.free), paid → Paid ticket (registration.step3.ticket.paid)', $rows['ticket'][5]);
    $this->assertSame('A fixed part: registration.contact', $rows['contact'][5]);
    $this->assertSame('an email address', $rows['contact.email'][5]);

    $answers = ['venue' => 'harbour', 'room' => 'harbour_deck', 'pricing' => 'paid'];
    $rows = $this->panelRows(RegistrationStep3Resource::class, $answers + $stored);
    $this->assertSame('one of Auditorium, Upper deck', $rows['room'][5]);
    $this->assertSame('from 1 to 150', $rows['capacity'][5]);
    $this->assertSame('the paid variant', $rows['ticket'][7]);
    $this->assertSame(['ticket.price', 'float', 'Price', 'yes', '—', 'at least 0.01', '—', 'as declared'], $rows['ticket.price']);

    // The panel shows the contract itself: the ticket as one conditional
    // per variant, keyed on the pricing beside it, and no widget hints.
    $this->serveRoute('surface_examples.step3');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $panel = $form[SurfaceOperationForm::SURFACE_KEY][SurfaceOperationForm::PANEL_KEY][ExamplePanels::CONTRACT];
    $this->assertSame('The schema for these answers', (string) $panel['contract']['#title']);
    $contract = json_decode((string) $panel['contract']['json']['#code'], TRUE);
    $this->assertSame('https://json-schema.org/draft/2020-12/schema', $contract['$schema']);
    $branches = [];
    foreach ($contract['allOf'] as $branch) {
      $this->assertSame(['pricing'], $branch['if']['required']);
      // Each refers to its variant's def, read here as one schema.
      $this->assertStringStartsWith('#/$defs/registration.step3.ticket.', $branch['then']['properties']['ticket']['$ref']);
      $branches[$branch['if']['properties']['pricing']['const']] = SchemaResolver::inline($branch['then']['properties']['ticket'], $contract);
    }
    $this->assertSame(['free', 'paid'], array_keys($branches));
    $this->assertSame(['note'], array_keys($branches['free']['properties']));
    $this->assertSame(['price', 'currency'], $branches['paid']['required']);
    $this->assertSame(['EUR', 'GBP', 'USD'], array_column($branches['paid']['properties']['currency']['oneOf'], 'title'));
    $ticket = $contract['properties']['ticket']['x-surface'];
    $said = [$ticket['by'], array_keys($ticket['variants']), $ticket['chosen']];
    $this->assertSame(['pricing', ['free', 'paid'], 'free'], $said);
    $this->assertArrayNotHasKey('widget', $ticket);
    $this->assertSame(['venue'], $contract['properties']['room']['x-surface']['dependsOn']);

    // Beside it, the full schema: the same keys as declared, with every
    // dependency that can be listed stated for every value, so the room
    // the table says is "one of Main hall, East room" lists all six here.
    $this->assertSame('The full schema, for any answers', (string) $panel['full']['#title']);
    $this->assertStringContainsString('does not change when an answer changes', (string) $panel['full']['note']['#markup']);
    $full = json_decode((string) $panel['full']['json']['#code'], TRUE);
    $this->assertCount(2, $contract['properties']['room']['oneOf']);
    $this->assertCount(6, $full['properties']['room']['oneOf']);
    $this->assertArrayNotHasKey('maximum', $full['properties']['capacity'], 'With no room, no upper bound.');
    $this->assertSame(['dependsOn', 'enumerated'], array_keys($full['properties']['room']['x-surface']));
    $narrows = [];
    foreach ($full['allOf'] as $branch) {
      $on = implode(',', array_keys($branch['if']['properties']));
      $narrows[$on][] = implode(',', array_keys($branch['then']['properties']));
    }
    $this->assertSame([
      'venue' => ['room', 'room', 'room'],
      'room' => ['capacity', 'capacity', 'capacity', 'capacity', 'capacity', 'capacity'],
      'pricing' => ['ticket', 'ticket'],
    ], $narrows);

    // Below them, labeled, what the derived tool can advertise.
    $blocks = ['contract', 'full', 'tool'];
    $this->assertSame($blocks, array_values(array_intersect(Element::children($panel), $blocks)));
    $this->assertStringContainsString('What the Tool API can advertise', (string) $panel['tool']['#title']);
    $this->assertStringContainsString('surface:registration.step3:configure', (string) $panel['tool']['#title']);
    $this->assertStringContainsString('cannot say a shape chosen by a sibling key', (string) $panel['tool']['note']['#markup']);
    $schema = json_decode((string) $panel['tool']['json']['#code'], TRUE);
    $this->assertSame(['values', 'dry_run'], array_keys($schema['properties']));
    $this->assertArrayNotHasKey('allOf', $schema['properties']['values']);
  }

  /**
   * Tests a changed venue rebuilds the rooms and the panel together.
   *
   * What a browser does when the venue select changes: a POST naming the
   * venue as the triggering element, and the container that comes back.
   */
  public function testChangingTheVenueRebuildsTheRoomsAndThePanel(): void {
    // Anonymous, given the permission, so the form carries no token a
    // test would have to forge.
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->serveRoute('surface_examples.step3', 'POST');
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'surface_operation_form_surface_examples_step3',
      'surface' => [
        'title' => 'Spring meetup',
        'capacity' => '50',
        'open' => '1',
        'venue' => 'harbour',
        'room' => 'riverside_main',
        'pricing' => 'free',
        'ticket' => ['note' => ''],
        'contact' => ['email' => 'events@example.com', 'phone' => ''],
      ],
      '_triggering_element_name' => 'surface[venue]',
    ]);
    $form = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state);
    $this->assertTrue($form_state->isRebuilding());
    $this->assertSame([], $form_state->getErrors());
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    // The harbour's rooms and nothing else. The stored room is not among
    // them, so the select comes up on its empty option with the stored
    // room stashed behind it: a Save from here keeps it only if the venue
    // is put back, and refuses it otherwise.
    $this->assertSame(['', 'harbour_auditorium', 'harbour_deck'], array_keys($container['room']['#options']));
    $this->assertSame('', $container['room']['#value']);
    $this->assertSame('riverside_main', $container['room'][SurfaceWidgetBase::STALE_KEY]);
    $this->assertSame('room', $container[SurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);

    $rows = [];
    foreach ($container[SurfaceOperationForm::PANEL_KEY][ExamplePanels::CONTRACT]['keys']['#rows'] as $row) {
      $rows[$row['surface-key']] = $row['data'];
    }
    $this->assertSame('one of Auditorium, Upper deck', (string) $rows['room'][5]['data']['#markup']);
    $this->assertSame('narrowed', (string) $rows['room'][7]);

    // A key a runtime rule checks too: narrowed or not, the rule is said,
    // and which of them narrowed it is not.
    $status = new \ReflectionMethod(ContractPanel::class, 'status');
    $panel = $this->container->get('surface.contract_panel');
    $declared = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a', 'b']]);
    $narrowed = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a']]);
    $this->assertSame('narrowed, and checked at runtime', (string) $status->invoke($panel, $declared, $narrowed, FALSE, TRUE));
    $this->assertSame('as declared, checked at runtime', (string) $status->invoke($panel, $declared, $declared, FALSE, TRUE));
    $this->assertSame('narrowed', (string) $status->invoke($panel, $declared, $narrowed, FALSE, FALSE));
  }

  /**
   * Posts a step's form as the AJAX request a changed select sends.
   *
   * @param string $step
   *   The step's number.
   * @param array $stored
   *   What the step's configuration holds, over its installed values.
   * @param array $input
   *   The resource's input as the browser sent it.
   * @param string $trigger
   *   The resource key that was changed.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the rebuild.
   */
  protected function rebuildStep(string $step, array $stored, array $input, string $trigger): FormStateInterface {
    $config = $this->config('surface_examples.registration_step' . $step);
    foreach ($stored as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    // Anonymous, given the permission, so the form carries no token a
    // test would have to forge.
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->serveRoute('surface_examples.step' . $step, 'POST');
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'surface_operation_form_surface_examples_step' . $step,
      'surface' => $input,
      '_triggering_element_name' => 'surface[' . $trigger . ']',
    ]);
    $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state);
    $this->assertTrue($form_state->isRebuilding());
    $this->assertSame([], $form_state->getErrors());
    return $form_state;
  }

  /**
   * Tests a room the venue change orphaned caps no capacity.
   *
   * The report: stored at the riverside's east room, the venue moved to
   * the harbour with the room still posted. The room comes up on its
   * empty option standing for the stored room, and the capacity below it
   * is refined as the person sees the room — unanswered — so it is back
   * to its declared limit, not still capped by a room no longer on the
   * screen.
   */
  public function testAnOrphanedRoomNoLongerCapsTheCapacity(): void {
    $state = $this->rebuildStep('2', ['venue' => 'riverside', 'room' => 'riverside_east', 'capacity' => 50], [
      'title' => 'Spring meetup',
      'open' => '1',
      'venue' => 'harbour',
      'room' => 'riverside_east',
      'capacity' => '20',
    ], 'venue');
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame(['', 'harbour_auditorium', 'harbour_deck'], array_keys($container['room']['#options']));
    $this->assertSame('', $container['room']['#value']);
    $this->assertSame('riverside_east', $container['room'][SurfaceWidgetBase::STALE_KEY]);
    $this->assertSame('room', $container[SurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);
    $this->assertArrayNotHasKey('#max', $container['capacity'], 'With no room, no upper bound.');
    $this->assertSame(1, $container['capacity']['#min']);
    $this->assertArrayNotHasKey('#description', $container['capacity']);

    $response = $this->ajaxResponse($state);
    $capacity = $this->wrapperSelector($container['capacity']);
    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $capacity,
      $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $markup = $this->ajaxMarkup($response, $capacity);
    $this->assertStringNotContainsString('max=', $markup);
    $this->assertStringNotContainsString('Up to', $markup);
    // The panel beside it says the same thing.
    $rows = [];
    foreach ($container[SurfaceOperationForm::PANEL_KEY][ExamplePanels::CONTRACT]['keys']['#rows'] as $row) {
      $rows[$row['surface-key']] = $row['data'];
    }
    $this->assertSame('at least 1', (string) $rows['capacity'][5]['data']['#markup']);

    // The room stays stored: a rebuild is not a submit.
    $this->assertSame('riverside_east', $this->config('surface_examples.registration_step2')->get('room'));
  }

  /**
   * Tests the orphan cascade stops at the keys the venue decides.
   *
   * Step 3 has variants and an attached part beside the venue chain. The
   * venue moving orphans the room and frees the capacity; the ticket and
   * the contact refine against nothing it moved, so they are neither
   * replaced nor reset.
   */
  public function testTheOrphanCascadeLeavesTheTicketAndContactAlone(): void {
    $state = $this->rebuildStep('3', ['venue' => 'riverside', 'room' => 'riverside_east'], [
      'title' => 'Spring meetup',
      'open' => '1',
      'venue' => 'harbour',
      'room' => 'riverside_east',
      'capacity' => '20',
      'pricing' => 'free',
      'ticket' => ['note' => 'Bring a badge'],
      'contact' => ['email' => 'desk@example.com', 'phone' => '555 0100'],
    ], 'venue');
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('riverside_east', $container['room'][SurfaceWidgetBase::STALE_KEY]);
    $this->assertArrayNotHasKey('#max', $container['capacity']);
    $this->assertArrayNotHasKey('#description', $container['capacity']);
    $this->assertSame('Bring a badge', $container['ticket']['note']['#value']);
    $this->assertSame('desk@example.com', $container['contact']['email']['#value']);
    $this->assertSame('555 0100', $container['contact']['phone']['#value']);

    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $settings = $state->getUserInput()['surface'];
    $this->assertSame(['note' => 'Bring a badge'], $settings['ticket']);
    $this->assertSame(['email' => 'desk@example.com', 'phone' => '555 0100'], $settings['contact']);
  }

  /**
   * Tests the line counts the landing page shows stay screen sized.
   */
  public function testEachStepFitsOnOneScreen(): void {
    foreach ([RegistrationStep1Resource::class, RegistrationStep2Resource::class, RegistrationStep3Resource::class] as $class) {
      $lines = CodeLines::count((string) (new \ReflectionClass($class))->getFileName());
      $this->assertGreaterThan(10, $lines, $class);
      $this->assertLessThanOrEqual(45, $lines, $class);
    }
  }

}
