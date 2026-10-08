<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface_examples\Surface\RegistrationStep2Surface;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a submission that moves a dependency and its dependent at once.
 *
 * What a full submit is, as against an AJAX rebuild: every answer arrives
 * in one request, so the venue can move in the same request as the room
 * stored under the old venue is sent back. The run moved the venue, so
 * the room is this run's answer to a question it changed, and it is
 * refused — on every door: the pipeline, the generated form, and the
 * derived tool. The other half is the valid submission that moves both:
 * the form's elements have to be the ones the submitted answers ask for,
 * or Form API refuses the new venue's room as a choice it was never
 * offered, and a slot flipped to another variant has nowhere for its
 * keys to arrive.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FullSubmitTest extends DataSurfaceKernelTestBase {

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
   * Step 2 as the report found it stored: the harbour's upper deck.
   */
  protected const STORED = [
    'title' => 'Spring meetup',
    'capacity' => 50,
    'open' => TRUE,
    'venue' => 'harbour',
    'room' => 'harbour_deck',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    $config = $this->config('data_surface_examples.registration_step2');
    foreach (self::STORED as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
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
   * Submits through the pipeline, to the step's own target.
   *
   * @param class-string $class
   *   The surface class.
   * @param array $input
   *   The raw input.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(string $class, array $input): DataSurfaceResult {
    $surfaces = $this->container->get('data_surface.surfaces');
    $context = $surfaces->situation($class, 'configure');
    $surface = $surfaces->build($class, $context);
    return $this->pipeline()->submit($surface, $input, $surfaces->target($class, $context, $surface));
  }

  /**
   * Submits the generic form at a step's route, as one statement.
   *
   * @param string $route_name
   *   The route.
   * @param array $values
   *   Raw values keyed by surface key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(string $route_name, array $values): FormStateInterface {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceSituationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceSituationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Gets and clears the messages of one type.
   *
   * @param string $type
   *   The message type.
   *
   * @return string[]
   *   The messages, as text.
   */
  protected function messages(string $type): array {
    $messages = $this->container->get('messenger')->messagesByType($type);
    $this->container->get('messenger')->deleteByType($type);
    return array_map('strval', $messages);
  }

  /**
   * Reads what step 2 holds.
   *
   * @return array
   *   The stored values.
   */
  protected function storedStepTwo(): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('data_surface_examples.registration_step2')->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Reads what step 3 holds.
   *
   * @return array
   *   The stored values.
   */
  protected function storedStepThree(): array {
    $this->container->get('config.factory')->reset();
    return $this->config('data_surface_examples.registration_step3')->getRawData();
  }

  /**
   * Posts step 2's generic form the way a browser does.
   *
   * With the form's id and, for Save, no trigger name; for an AJAX
   * round trip, the trigger's name instead of the button. The keys the
   * caller leaves out are sent as stored.
   *
   * @param array $surface
   *   The surface's input, keyed by surface key.
   * @param array $extra
   *   The rest of the POST: the button, or the AJAX trigger's name.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the request.
   */
  protected function postStepTwo(array $surface, array $extra = ['op' => 'Save']): FormStateInterface {
    return $this->postStep(2, $surface + ['title' => 'Spring meetup', 'capacity' => '50', 'open' => '1'], $extra);
  }

  /**
   * Posts one step's generic form the way a browser does.
   *
   * @param int $step
   *   The step, 2 or 3.
   * @param array $surface
   *   The surface's input, keyed by surface key, complete.
   * @param array $extra
   *   The rest of the POST: the button, or the AJAX trigger's name.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the request.
   */
  protected function postStep(int $step, array $surface, array $extra): FormStateInterface {
    $route_name = 'data_surface_examples.step' . $step;
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_situation_form_data_surface_examples_step' . $step,
      'surface' => $surface,
    ] + $extra);
    try {
      $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
    }
    catch (EnforcedResponseException) {
      // The redirect a browser's successful submission answers with.
    }
    return $form_state;
  }

  /**
   * Allows the anonymous user to configure the examples.
   *
   * A browser-shaped POST carries no form token, which only an
   * anonymous request is not asked for.
   */
  protected function actAsAnonymousAdministrator(): void {
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Tests the pipeline refuses the room the submitted venue does not offer.
   */
  public function testThePipelineRefusesTheRoomOrphanedByTheSameRun(): void {
    $result = $this->submit(RegistrationStep2Surface::class, ['venue' => 'riverside', 'room' => 'harbour_deck']);

    // The room is exactly what is stored, but it is not stale: the run
    // moved the venue it is narrowed by, so this run chose it.
    $this->assertFalse($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame(['room'], $result->violations->keys());
    $this->assertFalse($result->violations->hasStale());
    $this->assertSame(self::STORED, $this->storedStepTwo());

    // Validated alone, without a target, the same answer.
    $surface = $this->surface(RegistrationStep2Surface::class);
    $violations = $this->pipeline()->validate($surface, ['venue' => 'riverside'] + self::STORED, self::STORED);
    $this->assertSame(['room'], $violations->keys());
    $this->assertFalse($violations->hasStale());

    // And the new venue's own room is accepted and written.
    $result = $this->submit(RegistrationStep2Surface::class, ['venue' => 'riverside', 'room' => 'riverside_east']);
    $this->assertTrue($result->committed);
    $this->assertSame('riverside', $this->storedStepTwo()['venue']);
    $this->assertSame('riverside_east', $this->storedStepTwo()['room']);
  }

  /**
   * Tests a stored room the site narrowed away is still stale.
   *
   * The boundary of the rule above: nothing this run sent moved the
   * venue, so the refusal is about what the site did, and it is kept.
   */
  public function testTheRoomGoneUnderAnUnmovedVenueIsStillStale(): void {
    $this->config('data_surface_examples.registration_step2')->set('room', 'harbour_gone')->save();

    $result = $this->submit(RegistrationStep2Surface::class, ['venue' => 'harbour', 'title' => 'Renamed']);

    $this->assertTrue($result->committed);
    $this->assertCount(1, $result->violations->stale());
    $this->assertSame('room', $result->violations->stale()[0]->key);
    $this->assertSame('harbour_gone', $this->storedStepTwo()['room']);

    // Moving the venue makes the same stored room this run's problem.
    $result = $this->submit(RegistrationStep2Surface::class, ['venue' => 'riverside']);
    $this->assertFalse($result->isValid());
    $this->assertSame(['room'], $result->violations->keys());
    $this->assertFalse($result->violations->hasStale());
  }

  /**
   * Tests the generated form refuses it, on the element, and says so.
   */
  public function testTheFormRefusesTheRoomOrphanedByTheSameSubmission(): void {
    $state = $this->submitTheForm('data_surface_examples.step2', [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'harbour_deck',
    ]);

    $this->assertArrayHasKey('surface][room', $state->getErrors());
    $this->assertSame(self::STORED, $this->storedStepTwo());
    $this->assertSame([], $this->messages('status'));

    // The valid version of the same move is built for the new venue, so
    // its room is among the offered ones, and it is saved and said so.
    $state = $this->submitTheForm('data_surface_examples.step2', [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
    ]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame('riverside_main', $this->storedStepTwo()['room']);
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));
  }

  /**
   * Tests Save after an AJAX venue change, room left on the empty option.
   *
   * What a browser with JavaScript does: the venue's rebuild left the
   * stored room standing behind the empty option, and Save posts the
   * empty room back with the new venue and the marker naming it. Empty
   * there means the stored room, and the stored room is this
   * submission's problem, since it moved the venue. Posted as a browser
   * posts, with the form's id and no trigger name.
   */
  public function testSaveAfterTheVenueRebuildRefusesTheLeftOverRoom(): void {
    $this->actAsAnonymousAdministrator();

    // The browser's version of the valid move first, since Form API
    // remembers for the rest of the request that any form errored: built
    // for the new venue, so its room is offered, and it is saved.
    $state = $this->postStepTwo(['venue' => 'riverside', 'room' => 'riverside_east']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame('riverside_east', $this->storedStepTwo()['room']);
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));

    $config = $this->config('data_surface_examples.registration_step2');
    foreach (self::STORED as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $state = $this->postStepTwo([
      'venue' => 'riverside',
      'room' => '',
      DataSurfaceFormBuilderInterface::STALE_MARKER_KEY => 'room',
    ]);
    $errors = array_map('strval', $state->getErrors());
    $this->assertArrayHasKey('surface][room', $errors);
    // Refused as the stored room, not as an unanswered one: the empty
    // select stood for it.
    $this->assertStringNotContainsString('is required', $errors['surface][room']);
    $this->assertSame(self::STORED, $this->storedStepTwo());
    $this->assertSame([], $this->messages('status'));
  }

  /**
   * Tests a full submit caps the capacity by the room it submits.
   *
   * The full-submit half of the orphan cascade, pinned: nothing is
   * discarded on Save, so the capacity is refined against the room this
   * submission chose — the new venue's main hall, 400 seats — and not
   * against the stored upper deck's 150, on the element the submission
   * is processed against and in the pipeline alike.
   */
  public function testFullSubmitRefinesTheCapacityAgainstTheSubmittedRoom(): void {
    $this->actAsAnonymousAdministrator();

    $state = $this->postStepTwo(['venue' => 'riverside', 'room' => 'riverside_main', 'capacity' => '300']);
    $this->assertSame(400, $state->getCompleteForm()['surface']['capacity']['#max']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));
    $stored = $this->storedStepTwo();
    $this->assertSame(['riverside', 'riverside_main', 300], [$stored['venue'], $stored['room'], $stored['capacity']]);

    // Over the submitted room's own limit, refused on the capacity.
    $state = $this->postStepTwo(['venue' => 'harbour', 'room' => 'harbour_deck', 'capacity' => '160']);
    $this->assertSame(150, $state->getCompleteForm()['surface']['capacity']['#max']);
    $this->assertArrayHasKey('surface][capacity', $state->getErrors());
    $this->assertSame('riverside_main', $this->storedStepTwo()['room']);
  }

  /**
   * Tests an untouched save keeps a room the site narrowed away.
   *
   * The venue did not move, so the room left on the empty option is the
   * stored room, kept, with the warning that it is no longer available.
   */
  public function testAnUntouchedSaveKeepsTheStaleRoom(): void {
    $this->actAsAnonymousAdministrator();
    $this->config('data_surface_examples.registration_step2')->set('room', 'harbour_gone')->save();

    $state = $this->postStepTwo([
      'title' => 'Renamed',
      'venue' => 'harbour',
      'room' => '',
      DataSurfaceFormBuilderInterface::STALE_MARKER_KEY => 'room',
    ]);

    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));
    $this->assertCount(1, $this->messages('warning'));
    $this->assertSame('harbour_gone', $this->storedStepTwo()['room']);
    $this->assertSame('Renamed', $this->storedStepTwo()['title']);
  }

  /**
   * Tests a required select on first entry, refused until chosen.
   *
   * Nothing stored and no declared default: the venue and the room come
   * up on the empty option, selected, and a save leaving them there is
   * refused in the surface's own words, with nothing to keep.
   */
  public function testRequiredSelectOnFirstEntryIsRefusedUntilChosen(): void {
    $this->config('data_surface_examples.registration_step2')->clear('venue')->clear('room')->save();
    $this->actAsAnonymousAdministrator();

    $state = $this->postStepTwo(['venue' => '', 'room' => '']);
    $venue = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY]['venue'];
    $this->assertSame('', $venue['#value']);
    $this->assertSame('- Select -', (string) $venue['#options']['']);
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::STALE_MARKER_KEY, $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY]);
    $errors = array_map('strval', $state->getErrors());
    $this->assertSame('Venue is required.', $errors['surface][venue']);
    $this->assertSame([], $this->messages('status'));
    $this->assertSame('Room is required.', $errors['surface][room']);
    $this->assertArrayNotHasKey('venue', $this->storedStepTwo());
    // Form API remembers for the rest of the request that a form errored,
    // so the chosen half is asked of the pipeline: an explicit choice is
    // all it takes.
    $result = $this->submit(RegistrationStep2Surface::class, ['venue' => 'harbour', 'room' => 'harbour_deck']);
    $this->assertTrue($result->committed);
    $this->assertSame('harbour_deck', $this->storedStepTwo()['room']);
  }

  /**
   * Tests an AJAX rebuild reads a stale select left empty as stored.
   *
   * The venue is stale for what the site did — a venue no longer on the
   * list — so its select comes up on the empty option, and the browser
   * posts it back empty, with the marker, on every request. The room is
   * touched, which rebuilds the surface over AJAX. The venue is no
   * refinement target, so the discard rule never looks at it, and read
   * as it came it would be rebuilt as a key holding nothing: the stash
   * gone, and a Save from the rebuilt form clearing the stored venue.
   * It has to stand for the stored venue, on every rebuild.
   */
  public function testAjaxRebuildReadsStaleSelectLeftEmptyAsTheStoredValue(): void {
    $this->actAsAnonymousAdministrator();
    $this->config('data_surface_examples.registration_step2')->set('venue', 'demolished')->save();

    $state = $this->postStepTwo(
      [
        'venue' => '',
        'room' => 'harbour_deck',
        DataSurfaceFormBuilderInterface::STALE_MARKER_KEY => 'venue',
      ],
      ['_triggering_element_name' => 'surface[room]'],
    );

    $this->assertTrue($state->isRebuilding());
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $venue = $container['venue'];
    // Still the empty option, standing for the stored venue.
    $this->assertSame('', $venue['#value']);
    $this->assertSame('demolished', $venue[DataSurfaceWidgetBase::STALE_KEY]);
    $this->assertArrayNotHasKey('demolished', $venue['#options']);
    $this->assertSame('venue', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);
    // A rebuild writes nothing.
    $this->assertSame('demolished', $this->storedStepTwo()['venue']);
  }

  /**
   * Tests the derived tool refuses it, the third door.
   */
  public function testTheToolRefusesTheRoomOrphanedByTheSameCall(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:registration.step2:configure');
    $tool->setInputValue(SituationInputs::VALUES, [
      'title' => 'Orphaned',
      'venue' => 'riverside',
      'room' => 'harbour_deck',
    ]);
    $tool->setInputValue(SituationInputs::DRY_RUN, FALSE);
    $tool->execute();
    $result = $tool->getResult();

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('room:', PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $this->assertSame(self::STORED, $this->storedStepTwo());
  }

  /**
   * Tests a slot flipped in the same submission is built as the new one.
   *
   * Step 3 stores the free ticket. Moving pricing to paid and sending
   * the paid ticket's keys is valid and written; sending the free
   * ticket's keys under paid is refused, on every door.
   */
  public function testTheSlotFlippedInTheSameSubmission(): void {
    $base = [
      'title' => 'Gala',
      'capacity' => '20',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_east',
      'contact' => ['email' => 'gala@example.com', 'phone' => ''],
    ];
    $before = $this->storedStepThree();
    $this->assertSame('free', $before['pricing']);

    // The free variant's keys under paid: refused by the pipeline...
    $free_keys = ['pricing' => 'paid', 'ticket' => ['note' => 'Donations welcome']];
    $result = $this->submit(RegistrationStep3Surface::class, $free_keys);
    $this->assertFalse($result->isValid());
    $this->assertSame(['ticket'], $result->violations->keys());

    // ...and by the form, which writes nothing and says nothing went.
    $state = $this->submitTheForm('data_surface_examples.step3', $base + [
      'pricing' => 'paid',
      'ticket' => ['note' => 'Donations welcome'],
    ]);
    $this->assertNotSame([], $state->getErrors());
    $this->assertSame([], $this->messages('status'));
    $this->assertSame($before, $this->storedStepThree());

    // The paid ticket's keys under paid: the form is built as the paid
    // variant, so its keys have elements to arrive in, and it is saved.
    $state = $this->submitTheForm('data_surface_examples.step3', $base + [
      'pricing' => 'paid',
      'ticket' => ['price' => '25', 'currency' => 'USD'],
    ]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));
    $after = $this->storedStepThree();
    $this->assertSame('paid', $after['pricing']);
    $this->assertEquals(['price' => 25.0, 'currency' => 'USD'], $after['ticket']);
    $this->assertSame('riverside_east', $after['room']);
    $this->assertSame('Gala', $after['title']);
  }

  /**
   * Tests a changed venue replaces the room, the capacity and the panel.
   *
   * And nothing else: not the venue select the person just changed, and
   * not the container around everything. The room refines against the
   * venue and the capacity against the room, so both move; the panel
   * describes the surface as the answers stand, so it moves on every
   * rebuild. In declaration order, which puts the room first.
   */
  public function testChangingTheVenueReplacesItsDependentsAndThePanel(): void {
    $this->actAsAnonymousAdministrator();
    $state = $this->postStepTwo(
      ['venue' => 'riverside', 'room' => 'harbour_deck'],
      ['_triggering_element_name' => 'surface[venue]'],
    );
    $this->assertTrue($state->isRebuilding());
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);

    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    // The venue is no target, so it has no wrapper to be replaced by,
    // and neither it nor the container is replaced.
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::REFRESH_ID_KEY, $container['venue']);
    $this->assertNotContains('#' . $container['#attributes']['id'], $this->ajaxSelectors($response, 'replaceWith'));
    // The rebuilt room is the riverside's, on its empty option.
    $room = $this->ajaxMarkup($response, $this->wrapperSelector($container['room']));
    $this->assertStringContainsString('riverside_main', $room);
    $this->assertStringNotContainsString('harbour_auditorium', $room);

    // The stored room is not a riverside room, so the rebuild stands the
    // room's empty option for it, and the marker has to say so on the
    // page: taken out wherever it was, and put back as the rebuild left
    // it, inside the container.
    $this->assertSame('room', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value']);
    $wrapper = '#' . $container['#attributes']['id'];
    $this->assertSame([$wrapper . '__stale', $wrapper . '__messages'], $this->ajaxSelectors($response, 'remove'));
    $this->assertSame([$wrapper], $this->ajaxSelectors($response, 'append'));
    $marker = $this->ajaxMarkup($response, $wrapper);
    $this->assertStringContainsString('id="' . substr($wrapper, 1) . '__stale"', $marker);
    $this->assertStringContainsString('name="surface[@stale]"', $marker);
    $this->assertStringContainsString('value="room"', $marker);
    // Nothing was said, so nothing is printed.
    $this->assertSame([], $this->ajaxSelectors($response, 'prepend'));

    // Moving the venue back shows the stored room chosen again: nothing
    // is stale, so the marker is taken out and not put back. Posted as
    // the page now stands — the room on the empty option the response
    // put there, the marker it appended — against the form the first
    // request rebuilt and cached, with the container id the trigger was
    // rendered with, which is what a browser sends.
    $replaced = $this->ajaxSelectors($response, 'replaceWith');
    $state = $this->postStepTwo(
      [
        'venue' => 'harbour',
        'room' => '',
        DataSurfaceFormBuilderInterface::STALE_MARKER_KEY => 'room',
      ],
      [
        '_triggering_element_name' => 'surface[venue]',
        'form_build_id' => $state->getCompleteForm()['#build_id'],
        DataSurfaceFormBuilderInterface::WRAPPER_INPUT => substr($wrapper, 1),
      ],
    );
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame('harbour_deck', $container['room']['#value']);
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::STALE_MARKER_KEY, $container);
    $response = $this->ajaxResponse($state);
    // The same wrappers as the first response named: the ids on the page.
    $this->assertSame($replaced, $this->ajaxSelectors($response, 'replaceWith'));
    $this->assertSame([$wrapper . '__stale', $wrapper . '__messages'], $this->ajaxSelectors($response, 'remove'));
    $this->assertSame([], $this->ajaxSelectors($response, 'append'));
  }

  /**
   * Tests a changed room replaces the capacity and the panel, not itself.
   *
   * The room is a target of the venue and a dependency of the capacity.
   * Touching it moves the capacity's maximum, which is the change the
   * person has to see, and nothing above it.
   */
  public function testChangingTheRoomReplacesOnlyTheCapacityAndThePanel(): void {
    $this->actAsAnonymousAdministrator();
    $state = $this->postStepTwo(
      ['venue' => 'harbour', 'room' => 'harbour_auditorium'],
      ['_triggering_element_name' => 'surface[room]'],
    );
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);

    $replaced = $this->ajaxSelectors($response, 'replaceWith');
    $this->assertSame([
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $replaced);
    // The room has a wrapper, being a target of the venue, and it is the
    // trigger here, so it is the one wrapper that must not be named.
    $this->assertNotContains($this->wrapperSelector($container['room']), $replaced);
    // The capacity now allows what the auditorium seats, and says so
    // under the field.
    $capacity = $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity']));
    $this->assertStringContainsString('max="800"', $capacity);
    $this->assertStringContainsString('Up to 800 for the Auditorium.', $capacity);
    // Nothing stale, so the marker is taken out and nothing put back.
    $this->assertSame([], $this->ajaxSelectors($response, 'append'));
  }

  /**
   * Tests a changed pricing replaces the ticket slot and the panel.
   *
   * The slot is replaced by its own wrapper, whole: the variant's keys
   * are a different set, so there is nothing inside the old one to keep.
   */
  public function testChangingThePricingReplacesTheTicketSlot(): void {
    $this->actAsAnonymousAdministrator();
    $state = $this->postStep(3, [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'library',
      'room' => 'library_reading',
      'pricing' => 'paid',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
    ], ['_triggering_element_name' => 'surface[pricing]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);

    $this->assertSame([
      $this->wrapperSelector($container['ticket']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::REFRESH_ID_KEY, $container['pricing']);
    // The paid variant's keys, and not the free one's.
    $ticket = $this->ajaxMarkup($response, $this->wrapperSelector($container['ticket']));
    $this->assertStringContainsString('name="surface[ticket][price]"', $ticket);
    $this->assertStringNotContainsString('name="surface[ticket][note]"', $ticket);

    // And the venue on this step moves the room and the capacity, as on
    // step two, and not the ticket.
    $state = $this->postStep(3, [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'harbour',
      'room' => 'library_reading',
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
    ], ['_triggering_element_name' => 'surface[venue]']);
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame([
      $this->wrapperSelector($container['room']),
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($this->ajaxResponse($state), 'replaceWith'));
  }

  /**
   * Tests an error on the trigger itself is printed inside the container.
   *
   * The one value a refinement request judges is the trigger's own, and
   * a value its select never offered is refused before anything is
   * rebuilt. The render-array path printed that inside the container it
   * replaced; the commands print it in the same place, after taking the
   * previous request's messages away, and replace nothing else's markup
   * with anything new.
   */
  public function testAnErrorOnTheTriggerIsPrintedInsideTheContainer(): void {
    $this->actAsAnonymousAdministrator();
    $state = $this->postStepTwo(
      ['venue' => 'harbour', 'room' => 'library_garden'],
      ['_triggering_element_name' => 'surface[room]'],
    );
    $this->assertFalse($state->isRebuilding());
    $this->assertNotSame([], $state->getErrors());
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);
    $wrapper = '#' . $container['#attributes']['id'];
    $this->assertSame([$wrapper . '__stale', $wrapper . '__messages'], $this->ajaxSelectors($response, 'remove'));
    $this->assertSame([$wrapper], $this->ajaxSelectors($response, 'prepend'));
    $messages = $this->ajaxMarkup($response, $wrapper);
    $this->assertStringContainsString('id="' . substr($wrapper, 1) . '__messages"', $messages);
    $this->assertStringContainsString('is not allowed', $messages);
    // Printed once, and gone from the messenger with it.
    $this->assertSame([], $this->container->get('messenger')->all());
  }

  /**
   * Tests a rebuild keeps the container id the page holds.
   *
   * An AJAX request makes every generated id random, so a rebuild would
   * otherwise name wrappers the page has never seen: the trigger sends
   * the id it was rendered with, and the rebuild takes it back. Only an
   * id this form could have generated is taken.
   */
  public function testTheRebuildKeepsTheIdThePageHolds(): void {
    $this->actAsAnonymousAdministrator();
    $held = 'data-surface-configure-wrapper--held-by-the-page';
    $state = $this->postStepTwo(
      ['venue' => 'harbour', 'room' => 'harbour_auditorium'],
      [
        '_triggering_element_name' => 'surface[room]',
        DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $held,
      ],
    );
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertSame($held, $container['#attributes']['id']);
    $this->assertSame($held . '--capacity', $container['capacity'][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);
    // Every trigger sends it back on the next request too.
    $this->assertSame($held, $container['venue']['#ajax']['submit'][DataSurfaceFormBuilderInterface::WRAPPER_INPUT]);
    $this->assertSame(['#' . $held . '--capacity', '#' . $held . '--data-surface-panel'], $this->ajaxSelectors($this->ajaxResponse($state), 'replaceWith'));

    foreach (['other-form-wrapper', 'data-surface-configure-wrapper"><b>'] as $forged) {
      $state = $this->postStepTwo(
        ['venue' => 'harbour', 'room' => 'harbour_auditorium'],
        [
          '_triggering_element_name' => 'surface[room]',
          DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $forged,
        ],
      );
      $this->assertStringStartsWith('data-surface-configure-wrapper', $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY]['#attributes']['id']);
      $this->assertNotSame($forged, $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY]['#attributes']['id']);
    }
  }

}
