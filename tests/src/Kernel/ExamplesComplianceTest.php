<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests step 4: the compliance module's alter of step 3.
 *
 * With the module on, step 3 gains an event licence and a stewards count
 * under the module's name, and its title is relabelled. Without a
 * licence the owner's capacity stops at a hundred: the alter's method on
 * the owner's key, watching the key the alter mounted itself. The
 * stewards' minimum follows the capacity: the alter's method on its own
 * key, watching the owner's. Step 3 does not change and does not know.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesComplianceTest extends DataSurfaceKernelTestBase {

  use ExamplesFormPostTrait;
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
    'data_surface_examples_compliance',
  ];

  /**
   * The module the alter lives in, which its keys are mounted under.
   */
  protected const MODULE = 'data_surface_examples_compliance';

  /**
   * The path the licence is mounted at.
   */
  protected const LICENCE = 'third_party_settings.' . self::MODULE . '.licence';

  /**
   * The path the stewards count is mounted at.
   */
  protected const STEWARDS = 'third_party_settings.' . self::MODULE . '.stewards';

  /**
   * An event in a room for four hundred, before the capacity is chosen.
   */
  protected const EVENT = [
    'title' => 'Meetup',
    'venue' => 'riverside',
    'room' => 'riverside_main',
    'pricing' => 'free',
    'contact' => ['email' => 'events@example.com'],
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
   * Builds step 3 in its configure situation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(): DataSurfaceInterface {
    $surfaces = $this->container->get('data_surface.surfaces');
    return $surfaces->build(RegistrationStep3Surface::class, $surfaces->situation(RegistrationStep3Surface::class, 'configure'));
  }

  /**
   * Reads one of the alter's keys off a surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param string $key
   *   The key, as the alter added it.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   Its definition.
   */
  protected function mounted(DataSurfaceInterface $surface, string $key): DataDefinitionInterface {
    $mount = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mount);
    $module = $mount->getPropertyDefinition(self::MODULE);
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $module);
    $definition = $module->getPropertyDefinition($key);
    $this->assertNotNull($definition);
    return $definition;
  }

  /**
   * Builds the alter's part of the values.
   *
   * @param string|null $licence
   *   The licence, or NULL for none.
   * @param int $stewards
   *   The stewards.
   *
   * @return array
   *   The third_party_settings value.
   */
  protected static function compliance(?string $licence, int $stewards = 1): array {
    return ['third_party_settings' => [self::MODULE => ['licence' => $licence, 'stewards' => $stewards]]];
  }

  /**
   * Validates values against step 3 and lists where it refused them.
   *
   * @param array $values
   *   The values, over the event.
   *
   * @return string[]
   *   The full paths of the violations.
   */
  protected function refusals(array $values): array {
    return array_map(
      static fn ($violation): string => $violation->fullPath(),
      iterator_to_array($this->pipeline()->validate($this->surface(), $values + self::EVENT)),
    );
  }

  /**
   * Submits values to step 3's own target.
   *
   * @param array $input
   *   The raw input.
   * @param bool $dry_run
   *   Whether to stop before the write.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(array $input, bool $dry_run = FALSE): DataSurfaceResult {
    $surfaces = $this->container->get('data_surface.surfaces');
    $context = $surfaces->situation(RegistrationStep3Surface::class, 'configure');
    $surface = $surfaces->build(RegistrationStep3Surface::class, $context);
    return $this->pipeline()->submit($surface, $input, $surfaces->target(RegistrationStep3Surface::class, $context, $surface), $dry_run);
  }

  /**
   * Tests the keys appear, the label changes, and the edges are read.
   */
  public function testTheAlterAddsItsKeysAndWatchesItsOwnLicence(): void {
    $surface = $this->surface();
    $this->assertSame('Public event title', (string) $surface->getDefinition('title')->getLabel());
    $this->assertSame('Event licence', (string) $this->mounted($surface, 'licence')->getLabel());
    $this->assertFalse($this->mounted($surface, 'licence')->isRequired());
    $this->assertTrue($this->mounted($surface, 'stewards')->isRequired());
    $this->assertSame([self::MODULE => ['stewards' => 1]], $surface->getDefault('third_party_settings'));

    // The capacity watches the owner's room and the alter's own licence,
    // by its path; the mount watches the capacity, for the stewards.
    $definitions = $surface->getDefinitions();
    $this->assertSame(['room', self::LICENCE], $definitions->dependencies('capacity'));
    $this->assertSame(['capacity'], $definitions->dependencies('third_party_settings'));
    $this->assertSame(['room', self::LICENCE], $definitions->refinementPaths()['capacity']);
    $this->assertSame(['capacity'], $definitions->refinementPaths()[self::STEWARDS]);
    $this->assertArrayNotHasKey('third_party_settings', $definitions->refinementPaths());
  }

  /**
   * Tests the alter's keys sit in one fieldset, which the alter titles.
   *
   * The map every module's keys are mounted in only groups them, so the
   * form draws it as a plain container, and this module's map is the one
   * details, titled by the alter's describe() of its own mount. The
   * served contract says the same: no title on the group, and `group`.
   */
  public function testTheKeysSitInOneFieldsetTitledCompliance(): void {
    $surface = $this->surface();
    $stored = $this->config('data_surface_examples.registration_step3')->getRawData();
    $form = $this->container->get('data_surface.form_builder')->buildSurfaceForm($surface, $stored, new FormState());
    $group = $form['third_party_settings'];
    $this->assertSame('container', $group['#type']);
    $this->assertArrayNotHasKey('#title', $group);
    $this->assertArrayNotHasKey('#description', $group);
    $this->assertSame([self::MODULE], Element::children($group));
    $this->assertSame('details', $group[self::MODULE]['#type']);
    $this->assertSame('Compliance', (string) $group[self::MODULE]['#title']);
    $this->assertSame(['licence', 'stewards'], Element::children($group[self::MODULE]));
    $this->assertSame('At least 1 steward for 50 attendees.', (string) $group[self::MODULE]['stewards']['#description']);
    $this->assertSame('Up to 100 without an event licence. With one, up to 400.', (string) $form['capacity']['#description']);

    $served = $this->container->get('data_surface.contract_emitter')
      ->emit($surface, $stored, 'registration.step3', 'configure', widgets: TRUE)->document['schema']['properties']['third_party_settings'];
    $this->assertArrayNotHasKey('title', $served);
    $this->assertArrayNotHasKey('description', $served);
    $this->assertSame(['fieldset', TRUE], [$served['x-surface']['widget'], $served['x-surface']['group']]);
    $this->assertSame('Compliance', $served['properties'][self::MODULE]['title']);
    $this->assertArrayNotHasKey('group', $served['properties'][self::MODULE]['x-surface']);
  }

  /**
   * Tests the licence lifts the ceiling back to the room's limit.
   */
  public function testWithoutLicenceTheCapacityStopsAtOneHundred(): void {
    $surface = $this->surface();
    $capacity = $surface->refine(self::EVENT)->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 100], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 100 without an event licence. With one, up to 400.', (string) $capacity->getDescription());

    $capacity = $surface->refine(self::EVENT + self::compliance('2048'))->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 400], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 400 for the Main hall.', (string) $capacity->getDescription());

    // A room that seats fewer than a hundred keeps its own limit and its
    // owner's words either way: a licence would change nothing there.
    $small = ['room' => 'library_reading', 'venue' => 'library'] + self::EVENT;
    $capacity = $surface->refine($small)->getDefinition('capacity');
    $this->assertSame(60, $capacity->getConstraints()['Range']['max']);
    $this->assertSame('Up to 60 for the Reading room.', (string) $capacity->getDescription());

    // Elsewhere the text names both ceilings, the room's read off the
    // owner's refined Range.
    $deck = ['room' => 'harbour_deck', 'venue' => 'harbour'] + self::EVENT;
    $this->assertSame('Up to 100 without an event licence. With one, up to 150.', (string) $surface->refine($deck)->getDefinition('capacity')->getDescription());

    $this->assertSame([], $this->refusals(['capacity' => 100] + self::compliance(NULL, 2)));
    $this->assertSame(['capacity'], $this->refusals(['capacity' => 150] + self::compliance(NULL, 3)));
    $this->assertSame([], $this->refusals(['capacity' => 150] + self::compliance('2048', 3)));
  }

  /**
   * Tests a licence that is not one is refused, in the alter's words.
   */
  public function testTheLicencePatternIsChecked(): void {
    $violations = iterator_to_array($this->pipeline()->validate($this->surface(), ['capacity' => 50] + self::compliance('204') + self::EVENT));
    $this->assertCount(1, $violations);
    $this->assertSame(self::LICENCE, $violations[0]->fullPath());
    $this->assertSame('A licence number is four digits, such as 2048.', (string) $violations[0]->message);
  }

  /**
   * Tests the stewards' minimum follows the capacity, and says so.
   */
  public function testTheStewardsFollowTheCapacity(): void {
    $surface = $this->surface();
    $stewards = $this->mounted($surface->refine(['capacity' => 150] + self::EVENT + self::compliance('2048')), 'stewards');
    $this->assertSame(['min' => 3], $stewards->getConstraints()['Range']);
    $this->assertSame('At least 3 stewards for 150 attendees.', (string) $stewards->getDescription());

    $stewards = $this->mounted($surface->refine(['capacity' => 20] + self::EVENT), 'stewards');
    $this->assertSame(['min' => 1], $stewards->getConstraints()['Range']);
    $this->assertSame('At least 1 steward for 20 attendees.', (string) $stewards->getDescription());

    $this->assertSame([self::STEWARDS], $this->refusals(['capacity' => 150] + self::compliance('2048', 2)));
  }

  /**
   * Tests the pipeline writes a large event with a licence, and not without.
   */
  public function testThePipelineRefusesLargeEventWithoutLicence(): void {
    $result = $this->submit(['capacity' => 150] + self::compliance(NULL, 3) + self::EVENT);
    $this->assertFalse($result->isValid());
    $this->assertSame(['capacity'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations)));
    $this->assertSame(50, $this->config('data_surface_examples.registration_step3')->get('capacity'));

    $result = $this->submit(['capacity' => 150] + self::compliance('2048', 3) + self::EVENT);
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $stored = $this->config('data_surface_examples.registration_step3');
    $this->assertSame(150, $stored->get('capacity'));
    $this->assertSame('2048', $stored->get(self::LICENCE));
    $this->assertSame(3, $stored->get(self::STEWARDS));
  }

  /**
   * Tests what each trigger replaces on the form.
   *
   * The licence moves the capacity's ceiling, and through the capacity
   * the stewards' minimum; the capacity moves the stewards. Each
   * replaces the one mounted key it moves, never the whole mount, and
   * never itself.
   */
  public function testTheLicenceAndTheCapacityReplaceWhatTheyMove(): void {
    $this->actAsAnonymousAdministrator();
    // A rebuild is built from what is stored: the shipped room, Riverside
    // Hall's main hall.
    $surface = [
      'title' => 'Spring meetup',
      'capacity' => '150',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
      'third_party_settings' => [self::MODULE => ['licence' => '2048', 'stewards' => '3']],
    ];
    $state = $this->postStep(3, $surface, ['_triggering_element_name' => 'surface[third_party_settings][' . self::MODULE . '][licence]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $stewards = $container['third_party_settings'][self::MODULE]['stewards'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($stewards),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $capacity = $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity']));
    $this->assertStringContainsString('max="400"', $capacity);
    $this->assertStringContainsString('Up to 400 for the Main hall.', $capacity);

    $state = $this->postStep(3, ['capacity' => '20'] + $surface, ['_triggering_element_name' => 'surface[capacity]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $stewards = $container['third_party_settings'][self::MODULE]['stewards'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($stewards),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $this->assertStringContainsString('At least 1 steward for 20 attendees.', $this->ajaxMarkup($response, $this->wrapperSelector($stewards)));
    // The licence triggers a rebuild and is no target, yet has a wrapper
    // of its own, for the one case it is replaced: its value refused.
    // The stewards are a target and trigger nothing.
    $this->assertArrayHasKey('#ajax', $container['third_party_settings'][self::MODULE]['licence']);
    $this->assertArrayHasKey(DataSurfaceFormBuilderInterface::REFRESH_ID_KEY, $container['third_party_settings'][self::MODULE]['licence']);
    $this->assertArrayNotHasKey('#ajax', $stewards);
    // Typed into, the licence and the capacity change on blur, so neither
    // takes focus back after its refresh.
    $this->assertTrue($container['third_party_settings'][self::MODULE]['licence']['#ajax']['disable-refocus']);
    $this->assertTrue($container['capacity']['#ajax']['disable-refocus']);
  }

  /**
   * Tests the licence says its format before anything is typed.
   */
  public function testTheLicenceSaysItsFormat(): void {
    $licence = $this->mounted($this->surface(), 'licence');
    $this->assertSame('The four digits after EV- on the licence, such as 2048. Required to host more than 100 people.', (string) $licence->getDescription());
    $this->assertSame(['2048'], DefinitionMetadata::getExamples($licence));
    $element = $this->container->get('plugin.manager.data_surface_widget')->getWidgetFor($licence)->buildElement($licence, NULL);
    $this->assertSame('2048', $element['#placeholder']);
    // The message carries the explanation; the input carries no pattern
    // for Form API or a browser to answer in vaguer words.
    $this->assertArrayNotHasKey('#pattern', $element);
    $this->assertArrayNotHasKey('pattern', $element['#attributes'] ?? []);
  }

  /**
   * Tests a licence in the wrong format is no licence to the refiner.
   *
   * The engine holds a watched value to its own key's refined definition
   * before handing it over; `EV-2048`, prefix and all, fails the Regex
   * that asks for the four digits only, so the alter's refiner is handed
   * NULL and the ceiling of 100 applies, exactly as with no licence. The
   * alter itself only asks whether one is there.
   */
  public function testAnInvalidLicenceIsNoLicence(): void {
    $surface = $this->surface();
    $capacity = $surface->refine(self::EVENT + self::compliance('EV-2048'))->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 100], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 100 without an event licence. With one, up to 400.', (string) $capacity->getDescription());

    // Downstream of it, a capacity above that ceiling is itself refused,
    // so the stewards' minimum is not refined against it.
    $stewards = $this->mounted($surface->refine(['capacity' => 250] + self::EVENT + self::compliance('EV-2048')), 'stewards');
    $this->assertArrayNotHasKey('Range', $stewards->getConstraints());
    $stewards = $this->mounted($surface->refine(['capacity' => 250] + self::EVENT + self::compliance('2048')), 'stewards');
    $this->assertSame(['min' => 5], $stewards->getConstraints()['Range']);
  }

  /**
   * Tests the pipeline refuses the licence and the ceiling, both at once.
   */
  public function testThePipelineRefusesTheInvalidLicenceAndTheCeiling(): void {
    $violations = iterator_to_array($this->pipeline()->validate($this->surface(), ['capacity' => 150] + self::compliance('EV-2048', 3) + self::EVENT));
    $messages = [];
    foreach ($violations as $violation) {
      $messages[$violation->fullPath()] = (string) $violation->message;
    }
    $this->assertSame(['capacity', self::LICENCE], array_keys($messages));
    $this->assertSame('A licence number is four digits, such as 2048.', $messages[self::LICENCE]);
    $this->assertStringContainsString('100', $messages['capacity']);
    $this->assertStringNotContainsString('400', $messages['capacity']);
  }

  /**
   * Tests an invalid licence is said under the licence, once.
   *
   * The refinement request holds the licence's own violation back, so
   * the rebuild goes ahead and the capacity comes back with the
   * no-licence ceiling; the response replaces the licence too, with the
   * error under it, and prints it nowhere else. Its next request, the
   * licence fixed, replaces it again without the error.
   */
  public function testAnInvalidLicenceIsSaidUnderItOnce(): void {
    $this->actAsAnonymousAdministrator();
    $message = 'A licence number is four digits, such as 2048.';
    $surface = [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
      'third_party_settings' => [self::MODULE => ['licence' => 'EV-2048', 'stewards' => '1']],
    ];
    $trigger = ['_triggering_element_name' => 'surface[third_party_settings][' . self::MODULE . '][licence]'];
    $state = $this->postStep(3, $surface, $trigger);
    // No Form API error, or Form API would have skipped the rebuild.
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $licence = $container['third_party_settings'][self::MODULE]['licence'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container['third_party_settings'][self::MODULE]['stewards']),
      $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]),
      $this->wrapperSelector($licence),
    ], $this->ajaxSelectors($response, 'replaceWith'));

    $capacity = $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity']));
    $this->assertStringContainsString('max="100"', $capacity);
    $this->assertStringContainsString('Up to 100 without an event licence. With one, up to 400.', $capacity);

    $markup = $this->ajaxMarkup($response, $this->wrapperSelector($licence));
    $this->assertSame(1, substr_count($markup, $message));
    $this->assertStringContainsString('aria-invalid="true"', $markup);
    $this->assertStringContainsString('form-item--error-message', $markup);
    $this->assertStringContainsString('value="EV-2048"', $markup);
    // Said under the licence and nowhere else: no messages block, and
    // nothing left in the messenger for the next page.
    $this->assertSame([], $this->ajaxSelectors($response, 'prepend'));
    $this->assertSame([], $this->container->get('messenger')->all());
    $said = 0;
    foreach ($response->getCommands() as $command) {
      if (($command['selector'] ?? NULL) !== $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY])) {
        $said += substr_count((string) ($command['data'] ?? ''), $message);
        $this->assertStringNotContainsString('is not in the right format', (string) ($command['data'] ?? ''));
      }
    }
    $this->assertSame(1, $said);
    // The panel explains the licence by the same sentence, never by its
    // pattern.
    $panel = $this->ajaxMarkup($response, $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]));
    $this->assertMatchesRegularExpression('#data-surface-key="' . preg_quote(self::LICENCE, '#') . '">(?:(?!</tr>).)*<td>' . preg_quote($message, '#') . '</td>#s', $panel);

    // Fixed, and sent with the marker the replaced licence carries.
    $surface['third_party_settings'][self::MODULE]['licence'] = '2048';
    $state = $this->postStep(3, $surface, $trigger + [DataSurfaceFormBuilderInterface::INVALID_INPUT => '1']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $licence = $container['third_party_settings'][self::MODULE]['licence'];
    $response = $this->ajaxResponse($state);
    $this->assertContains($this->wrapperSelector($licence), $this->ajaxSelectors($response, 'replaceWith'));
    $markup = $this->ajaxMarkup($response, $this->wrapperSelector($licence));
    $this->assertStringNotContainsString($message, $markup);
    $this->assertStringNotContainsString('aria-invalid', $markup);
    $this->assertStringContainsString('max="400"', $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity'])));

    // Valid and unmarked: the licence is left where it is.
    $state = $this->postStep(3, $surface, $trigger);
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $this->assertNotContains($this->wrapperSelector($container['third_party_settings'][self::MODULE]['licence']), $this->ajaxSelectors($this->ajaxResponse($state), 'replaceWith'));
  }

  /**
   * Tests a full submission refuses both, each in the surface's words.
   */
  public function testFullSubmissionRefusesBothInTheSurfaceWords(): void {
    $this->actAsAnonymousAdministrator();
    $surface = [
      'title' => 'Spring meetup',
      'capacity' => '150',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
      'third_party_settings' => [self::MODULE => ['licence' => 'EV-2048', 'stewards' => '3']],
    ];
    $state = $this->postStep(3, $surface, ['op' => 'Save']);
    $errors = array_map('strval', $state->getErrors());
    $this->assertSame('A licence number is four digits, such as 2048.', $errors['surface][third_party_settings][' . self::MODULE . '][licence'] ?? NULL);
    $this->assertArrayHasKey('surface][capacity', $errors);
    $this->assertStringContainsString('100', $errors['surface][capacity']);
    foreach ($errors as $error) {
      $this->assertStringNotContainsString('is not in the right format', $error);
    }
    $this->assertSame(50, $this->config('data_surface_examples.registration_step3')->get('capacity'));
  }

  /**
   * Tests the discard cascade drops one mounted key, not the mount.
   *
   * A new venue orphans the stored room, which drops the capacity
   * answered under it, which drops the stewards count answered under
   * that capacity; the licence beside it is no target and stands.
   */
  public function testTheDiscardCascadeDropsOnlyTheStewards(): void {
    $stored = $this->config('data_surface_examples.registration_step3')->get();
    $input = [
      'venue' => 'riverside',
      'room' => 'library_reading',
      'capacity' => 80,
    ] + self::compliance('2048', 2) + $stored;
    $surface = $this->surface();
    $builder = $this->formBuilder();
    $this->assertSame(['room', 'capacity', self::STEWARDS], $builder->discardedRefinementInput($surface, $stored, $input));
    $overlay = $builder->refinementOverlay($surface, $stored, $input);
    $this->assertSame(['licence' => '2048'], $overlay['third_party_settings'][self::MODULE]);
    $this->assertSame(50, $overlay['capacity']);
  }

  /**
   * Tests example 5's calls answer as they do without this module.
   */
  public function testExampleFiveCallsAreUnchanged(): void {
    foreach (ExampleCalls::CALLS as $name => $call) {
      $result = $this->submit($call['values'], TRUE);
      $paths = array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations));
      $this->assertSame($name === 'over_capacity' ? ['capacity'] : [], $paths, $name);
    }
  }

}
