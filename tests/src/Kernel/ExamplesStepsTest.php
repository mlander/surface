<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\data_surface\SurfaceBuild\SituationRoute;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface_examples\CodeLines;
use Drupal\data_surface_examples\Form\RegistrationStep1ClassicForm;
use Drupal\data_surface_examples\Surface\RegistrationStep1Surface;
use Drupal\data_surface_examples\Surface\RegistrationStep2Surface;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the examples' steps 1 to 3, and the contract panel under them.
 *
 * Each step's route is served by the generic situation form, so what is
 * asserted is what a person sees: the form builds from the surface, a
 * refinement narrows what the next key offers, the slot resolves to the
 * chosen ticket, the contact part validates in its own frame, and the
 * panel says what each key allows as the answers stand.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesStepsTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
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
   *   Raw values keyed by surface key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceSituationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceSituationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Builds a step's surface in its configure situation.
   *
   * @param class-string $class
   *   The surface class.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(string $class): DataSurfaceInterface {
    $surfaces = $this->container->get('data_surface.surfaces');
    return $surfaces->build($class, $surfaces->situation($class, 'configure'));
  }

  /**
   * Builds the contract panel's rows for a surface and values.
   *
   * @param class-string $class
   *   The surface class.
   * @param array $values
   *   The values the form would be built with.
   *
   * @return array<string, array>
   *   The rows' cells, keyed by the key's path.
   */
  protected function panelRows(string $class, array $values): array {
    $surfaces = $this->container->get('data_surface.surfaces');
    $context = $surfaces->situation($class, 'configure');
    $served = new SituationRoute(
      $class,
      $this->container->get('data_surface.surface_registry')->getSituation($class, 'configure'),
      $context,
      NULL,
    );
    $panel = $this->container->get('data_surface_tool.contract_panel')
      ->buildPanel($served, $surfaces->build($class, $context), $values);
    $rows = [];
    foreach ($panel['keys']['#rows'] as $row) {
      $rows[$row['data-surface-key']] = array_map(
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
    $this->serveRoute('data_surface_examples.step1');
    $form = $this->container->get('form_builder')->getForm(DataSurfaceSituationForm::class);
    $container = $form[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame('textfield', $container['title']['#type']);
    $this->assertSame('Spring meetup', $container['title']['#default_value']);
    $this->assertSame(1000, $container['capacity']['#max']);
    $this->assertSame('checkbox', $container['open']['#type']);
    $this->assertArrayHasKey(DataSurfaceSituationForm::PANEL_KEY, $container);

    // A checkbox left clear is submitted as nothing at all.
    $state = $this->submitTheForm(['title' => 'Summer meetup', 'capacity' => '200', 'open' => NULL]);
    $this->assertSame([], $state->getErrors());
    $config = $this->config('data_surface_examples.registration_step1');
    $this->assertSame(['title' => 'Summer meetup', 'capacity' => 200, 'open' => FALSE], array_diff_key($config->get(), ['_core' => TRUE]));

    $refused = $this->submitTheForm(['title' => 'Summer meetup', 'capacity' => '1001', 'open' => '1']);
    $this->assertArrayHasKey('surface][capacity', $refused->getErrors());

    // The classic twin writes the same object the same way.
    $classic = new FormState();
    $classic->setValues(['title' => 'Classic meetup', 'capacity' => '300', 'open' => 1]);
    $this->container->get('form_builder')->submitForm(RegistrationStep1ClassicForm::class, $classic);
    $this->assertSame([], $classic->getErrors());
    $config = $this->config('data_surface_examples.registration_step1');
    $this->assertSame(['title' => 'Classic meetup', 'capacity' => 300, 'open' => TRUE], array_diff_key($config->get(), ['_core' => TRUE]));
  }

  /**
   * Tests step 2: the venue narrows the room, and the room the capacity.
   */
  public function testStepTwoNarrowsRoomByVenueAndCapacityByRoom(): void {
    $surface = $this->surface(RegistrationStep2Surface::class);
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
    $this->serveRoute('data_surface_examples.step2');
    $form = $this->container->get('form_builder')->getForm(DataSurfaceSituationForm::class);
    $container = $form[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame(['library_reading', 'library_garden'], array_keys(array_diff_key($container['room']['#options'], ['' => TRUE])));
    $this->assertArrayHasKey('#ajax', $container['venue']);
    $this->assertArrayHasKey('#ajax', $container['room']);
    $this->assertSame(60, $container['capacity']['#max']);
    $this->assertSame('Up to 60 for the Reading room.', (string) $container['capacity']['#description']);
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
    $this->assertSame('library_garden', $this->config('data_surface_examples.registration_step2')->get('room'));
  }

  /**
   * Tests step 3: the pricing resolves the ticket slot; the contact part.
   */
  public function testStepThreeResolvesTheTicketAndValidatesTheContact(): void {
    $surface = $this->surface(RegistrationStep3Surface::class);
    $entry = $surface->getDefinitions()->entry('ticket');
    $this->assertSame('pricing', $entry->slot?->by);
    $this->assertSame(['free', 'paid'], $entry->slot->variantIds());
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
    $this->config('data_surface_examples.registration_step3')
      ->set('pricing', 'paid')
      ->set('ticket', ['price' => 5.0, 'currency' => 'EUR'])
      ->save();
    $this->serveRoute('data_surface_examples.step3');
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
    $config = $this->config('data_surface_examples.registration_step3');
    $this->assertEquals(['price' => 9.5, 'currency' => 'GBP'], $config->get('ticket'));
    $this->assertSame('paid', $config->get('pricing'));
  }

  /**
   * Tests the panel says what each key allows as the answers stand.
   */
  public function testThePanelFollowsTheAnswers(): void {
    $stored = $this->config('data_surface_examples.registration_step3')->getRawData();
    $rows = $this->panelRows(RegistrationStep3Surface::class, $stored);
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
    $this->assertSame(['room', 'string', 'Room', 'yes', '—', 'one of Reading room, Garden room', 'venue', 'narrowed'], $rows['room']);
    $this->assertSame(['capacity', 'integer', 'Capacity', 'no', '50', 'from 1 to 60', 'room', 'narrowed'], $rows['capacity']);
    $this->assertSame('as declared', $rows['title'][7]);
    $this->assertSame('the free variant', $rows['ticket'][7]);
    $this->assertSame('an email address', $rows['contact.email'][5]);

    $answers = ['venue' => 'harbour', 'room' => 'harbour_deck', 'pricing' => 'paid'];
    $rows = $this->panelRows(RegistrationStep3Surface::class, $answers + $stored);
    $this->assertSame('one of Auditorium, Upper deck', $rows['room'][5]);
    $this->assertSame('from 1 to 150', $rows['capacity'][5]);
    $this->assertSame('the paid variant', $rows['ticket'][7]);
    $this->assertSame(['ticket.price', 'float', 'Price', 'yes', '—', 'at least 0.01', '—', 'as declared'], $rows['ticket.price']);

    // The panel names the tool and its schema.
    $this->serveRoute('data_surface_examples.step3');
    $form = $this->container->get('form_builder')->getForm(DataSurfaceSituationForm::class);
    $panel = $form[DataSurfaceSituationForm::SURFACE_KEY][DataSurfaceSituationForm::PANEL_KEY];
    $this->assertStringContainsString('data_surface:registration.step3:configure', (string) $panel['schema']['#title']);
    $schema = json_decode(htmlspecialchars_decode((string) $panel['schema']['json']['#value']), TRUE);
    $this->assertSame(['values', 'dry_run'], array_keys($schema['properties']));
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
    $this->serveRoute('data_surface_examples.step3', 'POST');
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_situation_form_data_surface_examples_step3',
      'surface' => [
        'title' => 'Spring meetup',
        'capacity' => '50',
        'open' => '1',
        'venue' => 'harbour',
        'room' => 'library_reading',
        'pricing' => 'free',
        'ticket' => ['note' => ''],
        'contact' => ['email' => 'events@example.com', 'phone' => ''],
      ],
      '_triggering_element_name' => 'surface[venue]',
    ]);
    $form = $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
    $this->assertTrue($form_state->isRebuilding());
    $this->assertSame([], $form_state->getErrors());
    $container = $form[DataSurfaceSituationForm::SURFACE_KEY];
    // The harbour's rooms and nothing else. The stored room is not among
    // them, so the select comes up on its empty option with the stored
    // room stashed behind it: a Save from here keeps it only if the venue
    // is put back, and refuses it otherwise.
    $this->assertSame(['', 'harbour_auditorium', 'harbour_deck'], array_keys($container['room']['#options']));
    $this->assertSame('', $container['room']['#value']);
    $this->assertSame('library_reading', $container['room'][DataSurfaceWidgetBase::STALE_KEY]);
    $this->assertSame('room', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);

    $rows = [];
    foreach ($container[DataSurfaceSituationForm::PANEL_KEY]['keys']['#rows'] as $row) {
      $rows[$row['data-surface-key']] = $row['data'];
    }
    $this->assertSame('one of Auditorium, Upper deck', $rows['room'][5]);
    $this->assertSame('narrowed', (string) $rows['room'][7]);
  }

  /**
   * Posts a step's form as the AJAX request a changed select sends.
   *
   * @param string $step
   *   The step's number.
   * @param array $stored
   *   What the step's configuration holds, over its installed values.
   * @param array $input
   *   The surface's input as the browser sent it.
   * @param string $trigger
   *   The surface key that was changed.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the rebuild.
   */
  protected function rebuildStep(string $step, array $stored, array $input, string $trigger): FormStateInterface {
    $config = $this->config('data_surface_examples.registration_step' . $step);
    foreach ($stored as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    // Anonymous, given the permission, so the form carries no token a
    // test would have to forge.
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->serveRoute('data_surface_examples.step' . $step, 'POST');
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_situation_form_data_surface_examples_step' . $step,
      'surface' => $input,
      '_triggering_element_name' => 'surface[' . $trigger . ']',
    ]);
    $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
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
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame(['', 'harbour_auditorium', 'harbour_deck'], array_keys($container['room']['#options']));
    $this->assertSame('', $container['room']['#value']);
    $this->assertSame('riverside_east', $container['room'][DataSurfaceWidgetBase::STALE_KEY]);
    $this->assertSame('room', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);
    $this->assertSame(1000, $container['capacity']['#max']);
    $this->assertArrayNotHasKey('#description', $container['capacity']);

    $response = $this->ajaxResponse($state);
    $capacity = $this->wrapperSelector($container['capacity']);
    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $capacity,
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $markup = $this->ajaxMarkup($response, $capacity);
    $this->assertStringContainsString('max="1000"', $markup);
    $this->assertStringNotContainsString('max="120"', $markup);
    $this->assertStringNotContainsString('Up to', $markup);
    // The panel beside it says the same thing.
    $rows = [];
    foreach ($container[DataSurfaceSituationForm::PANEL_KEY]['keys']['#rows'] as $row) {
      $rows[$row['data-surface-key']] = $row['data'];
    }
    $this->assertSame('from 1 to 1000', $rows['capacity'][5]);

    // The room stays stored: a rebuild is not a submit.
    $this->assertSame('riverside_east', $this->config('data_surface_examples.registration_step2')->get('room'));
  }

  /**
   * Tests the orphan cascade stops at the keys the venue decides.
   *
   * Step 3 has a slot and an attached part beside the venue chain. The
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
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame('riverside_east', $container['room'][DataSurfaceWidgetBase::STALE_KEY]);
    $this->assertSame(1000, $container['capacity']['#max']);
    $this->assertArrayNotHasKey('#description', $container['capacity']);
    $this->assertSame('Bring a badge', $container['ticket']['note']['#value']);
    $this->assertSame('desk@example.com', $container['contact']['email']['#value']);
    $this->assertSame('555 0100', $container['contact']['phone']['#value']);

    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $settings = $state->getUserInput()['surface'];
    $this->assertSame(['note' => 'Bring a badge'], $settings['ticket']);
    $this->assertSame(['email' => 'desk@example.com', 'phone' => '555 0100'], $settings['contact']);
  }

  /**
   * Tests the line counts the landing page shows stay screen sized.
   */
  public function testEachStepFitsOnOneScreen(): void {
    foreach ([RegistrationStep1Surface::class, RegistrationStep2Surface::class, RegistrationStep3Surface::class] as $class) {
      $lines = CodeLines::count((string) (new \ReflectionClass($class))->getFileName());
      $this->assertGreaterThan(10, $lines, $class);
      $this->assertLessThanOrEqual(45, $lines, $class);
    }
  }

}
