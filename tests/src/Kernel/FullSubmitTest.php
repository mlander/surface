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
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
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
   * Tests Save after an AJAX venue change, room left on the placeholder.
   *
   * What a browser with JavaScript does: the venue's rebuild handed the
   * stored room over to the stale placeholder, and Save posts the marker
   * back with the new venue. The marker means the stored room, and the
   * stored room is this submission's problem, since it moved the venue.
   * Posted as a browser posts, with the form's id and no trigger name.
   */
  public function testSaveAfterTheVenueRebuildRefusesTheLeftOverRoom(): void {
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $post = function (array $surface): FormStateInterface {
      $route_name = 'data_surface_examples.step2';
      $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
      $stack = $this->container->get('request_stack');
      $request = Request::create($route->getPath(), 'POST');
      $request->setSession($stack->getSession());
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
      $stack->push($request);
      $form_state = new FormState();
      $form_state->setUserInput([
        'form_id' => 'data_surface_situation_form_data_surface_examples_step2',
        'surface' => $surface + ['title' => 'Spring meetup', 'capacity' => '50', 'open' => '1'],
        'op' => 'Save',
      ]);
      try {
        $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
      }
      catch (EnforcedResponseException) {
        // The redirect a browser's successful submission answers with.
      }
      return $form_state;
    };

    // The browser's version of the valid move first, since Form API
    // remembers for the rest of the request that any form errored: built
    // for the new venue, so its room is offered, and it is saved.
    $state = $post(['venue' => 'riverside', 'room' => 'riverside_east']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame('riverside_east', $this->storedStepTwo()['room']);
    $this->assertSame(['The changes have been saved.'], $this->messages('status'));

    $config = $this->config('data_surface_examples.registration_step2');
    foreach (self::STORED as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $state = $post(['venue' => 'riverside', 'room' => DataSurfacePipelineInterface::KEEP_STALE]);
    $this->assertArrayHasKey('surface][room', $state->getErrors());
    $this->assertSame(self::STORED, $this->storedStepTwo());
    $this->assertSame([], $this->messages('status'));
  }

  /**
   * Tests the derived tool refuses it, the third door.
   */
  public function testTheToolRefusesTheRoomOrphanedByTheSameCall(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:registration.step2:configure');
    $tool->setInputValue(SituationInputs::VALUES, ['venue' => 'riverside', 'room' => 'harbour_deck']);
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

}
