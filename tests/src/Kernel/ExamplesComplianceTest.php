<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilder;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\ViolationSummary;
use Drupal\surface_examples\ExampleCalls;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples_compliance\Structure\ComplianceStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests step 4: the compliance module's alter of step 3.
 *
 * With the module on, step 3 gains an event license and a stewards count
 * under the module's name, and its title is relabelled. Without a
 * license the owner's capacity stops at a hundred: the alter's method on
 * the owner's key, watching the key the alter mounted itself. The
 * stewards' minimum follows the capacity: the alter's method on its own
 * key, watching the owner's. Step 3 does not change and does not know.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesComplianceTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_examples',
    'surface_examples_compliance',
  ];

  /**
   * The module the alter lives in, which its keys are mounted under.
   */
  protected const MODULE = 'surface_examples_compliance';

  /**
   * The path the license is mounted at.
   */
  protected const LICENSE = 'third_party_settings.' . self::MODULE . '.license';

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
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds step 3 in its configure operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function surface(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(RegistrationStep3Resource::class, $resources->operation(RegistrationStep3Resource::class, 'configure'));
  }

  /**
   * Reads one of the alter's keys off a built resource.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param string $key
   *   The key, as the alter added it.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   Its definition.
   */
  protected function mounted(SurfaceInterface $surface, string $key): DataDefinitionInterface {
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
   * @param string|null $license
   *   The license, or NULL for none.
   * @param int $stewards
   *   The stewards.
   *
   * @return array
   *   The third_party_settings value.
   */
  protected static function compliance(?string $license, int $stewards = 1): array {
    return ['third_party_settings' => [self::MODULE => ['license' => $license, 'stewards' => $stewards]]];
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
   * Submits values to step 3's own storage.
   *
   * @param array $input
   *   The raw input.
   * @param bool $dry_run
   *   Whether to stop before the write.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function submit(array $input, bool $dry_run = FALSE): SurfaceResult {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(RegistrationStep3Resource::class, 'configure');
    $surface = $resources->build(RegistrationStep3Resource::class, $context);
    return $this->pipeline()->submit($surface, $input, $resources->storage(RegistrationStep3Resource::class, $context, $surface), $dry_run);
  }

  /**
   * Tests the keys appear, the label changes, and the edges are read.
   */
  public function testTheAlterAddsItsKeysAndWatchesItsOwnLicense(): void {
    $surface = $this->surface();
    $this->assertSame('Public event title', (string) $surface->getDefinition('title')->getLabel());
    $this->assertSame('Event license', (string) $this->mounted($surface, 'license')->getLabel());
    $this->assertFalse($this->mounted($surface, 'license')->isRequired());
    $this->assertTrue($this->mounted($surface, 'stewards')->isRequired());
    $this->assertSame([self::MODULE => ['stewards' => 1]], $surface->getDefault('third_party_settings'));

    // The capacity watches the owner's room and the alter's own license,
    // by its path; the mount watches the capacity, for the stewards.
    $definitions = $surface->getDefinitions();
    $this->assertSame(['room', self::LICENSE], $definitions->dependencies('capacity'));
    $this->assertSame(['capacity'], $definitions->dependencies('third_party_settings'));
    $this->assertSame(['room', self::LICENSE], $definitions->refinementPaths()['capacity']);
    $this->assertSame(['capacity'], $definitions->refinementPaths()[self::STEWARDS]);
    $this->assertArrayNotHasKey('third_party_settings', $definitions->refinementPaths());

    // The keys are the compliance structure's, attached at the mount, its
    // capacity port wired to example 3's capacity.
    // Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
    $mounted = $definitions->entry('third_party_settings')->mounted[self::MODULE] ?? NULL;
    $this->assertNotNull($mounted);
    $this->assertSame(ComplianceStructure::class, $mounted->source);
    $this->assertSame('registration.compliance', $mounted->id);
    $this->assertSame(['capacity' => 'capacity'], $mounted->reads);
    $this->assertSame(['capacity'], array_keys($mounted->child->getDefinitions()->ports()));
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
    $stored = $this->config('surface_examples.registration_step3')->getRawData();
    $form = $this->container->get('surface.form_builder')->buildSurfaceForm($surface, $stored, new FormState());
    $group = $form['third_party_settings'];
    $this->assertSame('container', $group['#type']);
    $this->assertArrayNotHasKey('#title', $group);
    $this->assertArrayNotHasKey('#description', $group);
    $this->assertSame([self::MODULE], Element::children($group));
    $this->assertSame('details', $group[self::MODULE]['#type']);
    $this->assertSame('Compliance', (string) $group[self::MODULE]['#title']);
    $this->assertSame(['license', 'stewards'], Element::children($group[self::MODULE]));
    $this->assertSame('At least 1 steward for 50 attendees.', (string) $group[self::MODULE]['stewards']['#description']);
    $this->assertSame('Up to 100 without an event license.', (string) $form['capacity']['#description']);

    $served = $this->container->get('surface.contract_emitter')
      ->emit($surface, $stored, 'registration.step3', 'configure', widgets: TRUE)->document['schema']['properties']['third_party_settings'];
    $this->assertArrayNotHasKey('title', $served);
    $this->assertArrayNotHasKey('description', $served);
    $this->assertSame(['fieldset', TRUE], [$served['x-surface']['widget'], $served['x-surface']['group']]);
    $this->assertSame('Compliance', $served['properties'][self::MODULE]['title']);
    $this->assertArrayNotHasKey('group', $served['properties'][self::MODULE]['x-surface']);
    // Placed after the capacity: said on the fieldset, in both, and
    // nothing moved in the schema, where the values are.
    $this->assertSame('capacity', $group[self::MODULE][SurfaceFormBuilderInterface::PLACED_AFTER_KEY]);
    $this->assertSame('capacity', $served['properties'][self::MODULE]['x-surface']['after']);
    $this->assertArrayNotHasKey('after', $served['x-surface']);
  }

  /**
   * Tests the Compliance fieldset is drawn right after the capacity.
   *
   * The alter places its mount after the capacity its license lifts. The
   * form keeps the fieldset where its values are posted, inside the
   * grouping container, for everything Form API does with it, and the
   * container draws it after the capacity: between the capacity and the
   * pricing, the next key the owner declared. The grouping container,
   * left with nothing to draw, draws nothing.
   */
  public function testTheComplianceFieldsetIsDrawnAfterTheCapacity(): void {
    $route_name = 'surface_examples.step3';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $request = Request::create($route->getPath());
    $request->setSession($this->container->get('request_stack')->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->container->get('request_stack')->push($request);
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];

    // Built where it is stored: the paths every walk reads are unchanged.
    $details = $container['third_party_settings'][self::MODULE];
    $this->assertSame('Compliance', (string) $details['#title']);
    $this->assertSame(['surface', 'third_party_settings', self::MODULE, 'license'], $details['license']['#parents']);
    $this->assertSame('surface[third_party_settings][' . self::MODULE . '][stewards]', $details['stewards']['#name']);

    // Drawn after the capacity, and before the pricing.
    $drawn = SurfaceFormBuilder::preRenderSurfaceContainer($container);
    $order = array_values(array_filter(Element::children($drawn), static fn ($key): bool => in_array($key, [
      'title', 'open', 'venue', 'room', 'capacity', 'pricing', 'ticket', 'contact', '@placed.third_party_settings.' . self::MODULE,
    ], TRUE)));
    $this->assertSame([
      'title', 'open', 'venue', 'room', 'capacity', '@placed.third_party_settings.' . self::MODULE, 'pricing', 'ticket', 'contact',
    ], $order);
    $this->assertFalse($drawn['third_party_settings']['#access']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($container);
    $capacity = strpos($html, 'data-drupal-selector="edit-surface-capacity"');
    $compliance = strpos($html, 'data-drupal-selector="edit-surface-third-party-settings-surface-examples-compliance"');
    $pricing = strpos($html, 'data-drupal-selector="edit-surface-pricing"');
    $this->assertNotFalse($capacity);
    $this->assertNotFalse($compliance);
    $this->assertNotFalse($pricing);
    $this->assertLessThan($compliance, $capacity);
    $this->assertLessThan($pricing, $compliance);
    // A sibling of the capacity's wrapper and of the pricing: replacing
    // the capacity by its wrapper leaves the fieldset where it is.
    $xpath = new \DOMXPath(Html::load($html));
    $placed = '//details[@data-drupal-selector="edit-surface-third-party-settings-surface-examples-compliance"]';
    $before = $xpath->query($placed . '/preceding-sibling::*[1]')->item(0);
    $this->assertInstanceOf(\DOMElement::class, $before);
    $this->assertSame($container['capacity'][SurfaceFormBuilderInterface::REFRESH_ID_KEY], $before->getAttribute('id'));
    $this->assertSame(1, $xpath->query($placed . '/following-sibling::*[1][descendant-or-self::*[@data-drupal-selector="edit-surface-pricing"]]')->length);
    $this->assertStringContainsString('name="surface[third_party_settings][' . self::MODULE . '][license]"', substr($html, $compliance, $pricing - $compliance));
    $this->assertStringContainsString('name="surface[third_party_settings][' . self::MODULE . '][stewards]"', substr($html, $compliance, $pricing - $compliance));
    // Nothing drawn for the group it was stored in.
    $this->assertStringNotContainsString('data-drupal-selector="edit-surface-third-party-settings"', $html);
  }

  /**
   * Tests the license lifts the ceiling back to the room's limit.
   */
  public function testWithoutLicenseTheCapacityStopsAtOneHundred(): void {
    $surface = $this->surface();
    $capacity = $surface->refine(self::EVENT)->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 100], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 100 without an event license.', (string) $capacity->getDescription());

    $capacity = $surface->refine(self::EVENT + self::compliance('2048'))->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 400], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 400 for the Main hall.', (string) $capacity->getDescription());

    // A room that seats fewer than a hundred keeps its own limit, the
    // lower one: a rule only tightens, and says why only where it did, so
    // the room keeps its owner's words, license or not.
    $small = ['room' => 'library_reading', 'venue' => 'library'] + self::EVENT;
    $capacity = $surface->refine($small)->getDefinition('capacity');
    $this->assertSame(60, $capacity->getConstraints()['Range']['max']);
    $this->assertSame('Up to 60 for the Reading room.', (string) $capacity->getDescription());
    $capacity = $surface->refine($small + self::compliance('2048'))->getDefinition('capacity');
    $this->assertSame(60, $capacity->getConstraints()['Range']['max']);
    $this->assertSame('Up to 60 for the Reading room.', (string) $capacity->getDescription());

    // A room seating more than a hundred gets the rule's words.
    $deck = ['room' => 'harbour_deck', 'venue' => 'harbour'] + self::EVENT;
    $this->assertSame('Up to 100 without an event license.', (string) $surface->refine($deck)->getDefinition('capacity')->getDescription());

    $this->assertSame([], $this->refusals(['capacity' => 100] + self::compliance(NULL, 2)));
    $this->assertSame(['capacity'], $this->refusals(['capacity' => 150] + self::compliance(NULL, 3)));
    $this->assertSame([], $this->refusals(['capacity' => 150] + self::compliance('2048', 3)));
  }

  /**
   * Tests a license that is not one is refused, in the alter's words.
   */
  public function testTheLicensePatternIsChecked(): void {
    $violations = iterator_to_array($this->pipeline()->validate($this->surface(), ['capacity' => 50] + self::compliance('204') + self::EVENT));
    $this->assertCount(1, $violations);
    $this->assertSame(self::LICENSE, $violations[0]->fullPath());
    $this->assertSame('A license number is four digits, such as 2048.', (string) $violations[0]->message);
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
   * Tests a refusal a refiner caused names the keys it depends on.
   *
   * Too few stewards is about the capacity, and a capacity over a
   * hundred is about the room and the license: each refusal goes on to
   * say what each holds. The license's own pattern is its own rule, and
   * its refusal says nothing more (testTheLicensePatternIsChecked()).
   */
  public function testRefusalNamesWhatItDependsOn(): void {
    $messages = static fn (iterable $violations): array => array_map(
      static fn ($violation): string => strip_tags((string) $violation->message),
      iterator_to_array($violations),
    );
    $this->assertSame(
      ['This value should be 3 or more. This applies when Capacity is 150.'],
      $messages($this->pipeline()->validate($this->surface(), ['capacity' => 150] + self::compliance('2048', 2) + self::EVENT)),
    );
    $this->assertSame(
      ['This value should be between 1 and 100. This applies when Room is Main hall and Compliance: Event license is empty.'],
      $messages($this->pipeline()->validate($this->surface(), ['capacity' => 150] + self::compliance(NULL, 3) + self::EVENT)),
    );
  }

  /**
   * Tests the ceiling's refusal names the license the rule was handed.
   *
   * B11 of the 2026-10-20 reviews: a license its own pattern refuses is
   * handed to the capacity's rule as no license, so the ceiling is the
   * no-license one, and the refusal said "Event license is set", the
   * value sent, which reads as the opposite of the reason: a caller
   * would clear the license. It says what the rule was handed, and what
   * was refused.
   */
  public function testTheLicenseRefusalNamesWhatTheRuleWasHanded(): void {
    $violations = [];
    foreach ($this->pipeline()->validate($this->surface(), ['capacity' => 150] + self::compliance('EV-2048', 3) + self::EVENT) as $violation) {
      $violations[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    $this->assertSame(
      'This value should be between 1 and 100. This applies when Room is Main hall and Compliance: Event license is empty (EV-2048 was refused).',
      $violations['capacity'],
    );
    $this->assertSame('A license number is four digits, such as 2048.', $violations[self::LICENSE]);
  }

  /**
   * Tests the pipeline writes a large event with a license, and not without.
   */
  public function testThePipelineRefusesLargeEventWithoutLicense(): void {
    $result = $this->submit(['capacity' => 150] + self::compliance(NULL, 3) + self::EVENT);
    $this->assertFalse($result->isValid());
    $this->assertSame(['capacity'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations)));
    $this->assertSame(50, $this->config('surface_examples.registration_step3')->get('capacity'));

    $result = $this->submit(['capacity' => 150] + self::compliance('2048', 3) + self::EVENT);
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $stored = $this->config('surface_examples.registration_step3');
    $this->assertSame(150, $stored->get('capacity'));
    $this->assertSame('2048', $stored->get(self::LICENSE));
    $this->assertSame(3, $stored->get(self::STEWARDS));
  }

  /**
   * Tests a room the site closed is not offered, and not written.
   *
   * The alter's runtime refiner reads the closed rooms from State when
   * the values are held to it. Nothing closed, Riverside offers both its
   * rooms; with the East room closed it offers the main hall only and
   * says why, and a submit choosing the East room is refused at the room,
   * nothing written. Another venue's rooms are untouched.
   */
  public function testClosedRoomIsRefused(): void {
    $state = $this->container->get('state');
    $rooms = fn (array $values): array => array_keys($this->options()->resolve($this->surface()->refine($values)->getDefinition('room'))->options);
    $this->assertSame(['riverside_main', 'riverside_east'], $rooms(self::EVENT));

    $state->set('surface_examples_compliance.closed_rooms', ['riverside_east']);
    $this->assertSame(['riverside_main'], $rooms(self::EVENT));
    $this->assertSame('The East room is closed.', (string) $this->surface()->refine(self::EVENT)->getDefinition('room')->getDescription());
    $this->assertSame(['harbour_auditorium', 'harbour_deck'], $rooms(['venue' => 'harbour'] + self::EVENT));
    $state->set('surface_examples_compliance.closed_rooms', ['riverside_east', 'riverside_main']);
    $this->assertSame('The Main hall and the East room are closed.', (string) $this->surface()->refine(self::EVENT)->getDefinition('room')->getDescription());
    $state->set('surface_examples_compliance.closed_rooms', ['riverside_east']);

    $result = $this->submit(['room' => 'riverside_east', 'capacity' => 50] + self::compliance(NULL) + self::EVENT);
    $this->assertFalse($result->isValid());
    $this->assertSame(['room'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations)));
    $this->assertSame('riverside_main', $this->config('surface_examples.registration_step3')->get('room'));
    $result = $this->submit(['capacity' => 50] + self::compliance(NULL) + self::EVENT);
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
  }

  /**
   * Tests what each trigger replaces on the form.
   *
   * The license moves the capacity's ceiling, and through the capacity
   * the stewards' minimum; the capacity moves the stewards. Each
   * replaces the one mounted key it moves, never the whole mount, and
   * never itself.
   */
  public function testTheLicenseAndTheCapacityReplaceWhatTheyMove(): void {
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
      'third_party_settings' => [self::MODULE => ['license' => '2048', 'stewards' => '3']],
    ];
    $state = $this->postStep(3, $surface, ['_triggering_element_name' => 'surface[third_party_settings][' . self::MODULE . '][license]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $stewards = $container['third_party_settings'][self::MODULE]['stewards'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($stewards),
      $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $capacity = $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity']));
    $this->assertStringContainsString('max="400"', $capacity);
    $this->assertStringContainsString('Up to 400 for the Main hall.', $capacity);
    // The Compliance fieldset, drawn after the capacity, is not part of
    // what replaces it: it stays on the page as it was.
    $this->assertStringNotContainsString('<details', $capacity);

    $state = $this->postStep(3, ['capacity' => '20'] + $surface, ['_triggering_element_name' => 'surface[capacity]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $stewards = $container['third_party_settings'][self::MODULE]['stewards'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($stewards),
      $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]),
    ], $this->ajaxSelectors($response, 'replaceWith'));
    $this->assertStringContainsString('At least 1 steward for 20 attendees.', $this->ajaxMarkup($response, $this->wrapperSelector($stewards)));
    // The license triggers a rebuild and is no target, yet has a wrapper
    // of its own, for the one case it is replaced: its value refused.
    // The stewards are a target and trigger nothing.
    $this->assertArrayHasKey('#ajax', $container['third_party_settings'][self::MODULE]['license']);
    $this->assertArrayHasKey(SurfaceFormBuilderInterface::REFRESH_ID_KEY, $container['third_party_settings'][self::MODULE]['license']);
    $this->assertArrayNotHasKey('#ajax', $stewards);
    // Typed into, the license and the capacity change on blur, so neither
    // takes focus back after its refresh.
    $this->assertTrue($container['third_party_settings'][self::MODULE]['license']['#ajax']['disable-refocus']);
    $this->assertTrue($container['capacity']['#ajax']['disable-refocus']);
  }

  /**
   * Tests the license says its format before anything is typed.
   */
  public function testTheLicenseSaysItsFormat(): void {
    $license = $this->mounted($this->surface(), 'license');
    $this->assertSame('The four digits after EV- on the license, such as 2048. Required to host more than 100 people.', (string) $license->getDescription());
    $this->assertSame(['2048'], DefinitionMetadata::getExamples($license));
    $element = $this->container->get('plugin.manager.surface_widget')->getWidgetFor($license)->buildElement($license, NULL);
    // The example says it in the documents, not as a placeholder: an
    // empty field showing a value reads as filled.
    $this->assertArrayNotHasKey('#placeholder', $element);
    // The message carries the explanation; the input carries no pattern
    // for Form API or a browser to answer in vaguer words.
    $this->assertArrayNotHasKey('#pattern', $element);
    $this->assertArrayNotHasKey('pattern', $element['#attributes'] ?? []);
  }

  /**
   * Tests a license in the wrong format is no license to the refiner.
   *
   * The engine holds a watched value to its own key's refined definition
   * before handing it over; `EV-2048`, prefix and all, fails the Regex
   * that asks for the four digits only, so the alter's refiner is handed
   * NULL and the ceiling of 100 applies, exactly as with no license. The
   * alter itself only asks whether one is there.
   */
  public function testAnInvalidLicenseIsNoLicense(): void {
    $surface = $this->surface();
    $capacity = $surface->refine(self::EVENT + self::compliance('EV-2048'))->getDefinition('capacity');
    $this->assertSame(['min' => 1, 'max' => 100], $capacity->getConstraints()['Range']);
    $this->assertSame('Up to 100 without an event license.', (string) $capacity->getDescription());

    // Downstream of it, a capacity above that ceiling is itself refused,
    // so the stewards' minimum is not refined against it.
    $stewards = $this->mounted($surface->refine(['capacity' => 250] + self::EVENT + self::compliance('EV-2048')), 'stewards');
    $this->assertArrayNotHasKey('Range', $stewards->getConstraints());
    $stewards = $this->mounted($surface->refine(['capacity' => 250] + self::EVENT + self::compliance('2048')), 'stewards');
    $this->assertSame(['min' => 5], $stewards->getConstraints()['Range']);
  }

  /**
   * Tests the pipeline refuses the license and the ceiling, both at once.
   */
  public function testThePipelineRefusesTheInvalidLicenseAndTheCeiling(): void {
    $violations = iterator_to_array($this->pipeline()->validate($this->surface(), ['capacity' => 150] + self::compliance('EV-2048', 3) + self::EVENT));
    $messages = [];
    foreach ($violations as $violation) {
      $messages[$violation->fullPath()] = (string) $violation->message;
    }
    $this->assertSame(['capacity', self::LICENSE], array_keys($messages));
    $this->assertSame('A license number is four digits, such as 2048.', $messages[self::LICENSE]);
    $this->assertStringContainsString('100', $messages['capacity']);
    $this->assertStringNotContainsString('400', $messages['capacity']);
  }

  /**
   * Tests an invalid license is said under the license, once.
   *
   * The refinement request holds the license's own violation back, so
   * the rebuild goes ahead and the capacity comes back with the
   * no-license ceiling; the response replaces the license too, with the
   * error under it, and prints it nowhere else. Its next request, the
   * license fixed, replaces it again without the error.
   */
  public function testAnInvalidLicenseIsSaidUnderItOnce(): void {
    $this->actAsAnonymousAdministrator();
    $message = 'A license number is four digits, such as 2048.';
    $surface = [
      'title' => 'Spring meetup',
      'capacity' => '50',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
      'third_party_settings' => [self::MODULE => ['license' => 'EV-2048', 'stewards' => '1']],
    ];
    $trigger = ['_triggering_element_name' => 'surface[third_party_settings][' . self::MODULE . '][license]'];
    $state = $this->postStep(3, $surface, $trigger);
    // No Form API error, or Form API would have skipped the rebuild.
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $license = $container['third_party_settings'][self::MODULE]['license'];
    $response = $this->ajaxResponse($state);
    $this->assertSame([
      $this->wrapperSelector($container['capacity']),
      $this->wrapperSelector($container['third_party_settings'][self::MODULE]['stewards']),
      $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]),
      $this->wrapperSelector($license),
    ], $this->ajaxSelectors($response, 'replaceWith'));

    $capacity = $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity']));
    $this->assertStringContainsString('max="100"', $capacity);
    $this->assertStringContainsString('Up to 100 without an event license.', $capacity);

    $markup = $this->ajaxMarkup($response, $this->wrapperSelector($license));
    $this->assertSame(1, substr_count($markup, $message));
    $this->assertStringContainsString('aria-invalid="true"', $markup);
    $this->assertStringContainsString('form-item--error-message', $markup);
    $this->assertStringContainsString('value="EV-2048"', $markup);
    // Said under the license and nowhere else: no messages block, and
    // nothing left in the messenger for the next page.
    $this->assertSame([], $this->ajaxSelectors($response, 'prepend'));
    $this->assertSame([], $this->container->get('messenger')->all());
    $said = 0;
    foreach ($response->getCommands() as $command) {
      if (($command['selector'] ?? NULL) !== $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY])) {
        $said += substr_count((string) ($command['data'] ?? ''), $message);
        $this->assertStringNotContainsString('is not in the right format', (string) ($command['data'] ?? ''));
      }
    }
    $this->assertSame(1, $said);
    // The panel explains the license by the same sentence, never by its
    // pattern.
    $panel = $this->ajaxMarkup($response, $this->wrapperSelector($container[SurfaceOperationForm::PANEL_KEY]));
    $this->assertMatchesRegularExpression('#surface-key="' . preg_quote(self::LICENSE, '#') . '">(?:(?!</tr>).)*<td>' . preg_quote($message, '#') . '</td>#s', $panel);

    // Fixed, and sent with the marker the replaced license carries.
    $surface['third_party_settings'][self::MODULE]['license'] = '2048';
    $state = $this->postStep(3, $surface, $trigger + [SurfaceFormBuilderInterface::INVALID_INPUT => '1']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $license = $container['third_party_settings'][self::MODULE]['license'];
    $response = $this->ajaxResponse($state);
    $this->assertContains($this->wrapperSelector($license), $this->ajaxSelectors($response, 'replaceWith'));
    $markup = $this->ajaxMarkup($response, $this->wrapperSelector($license));
    $this->assertStringNotContainsString($message, $markup);
    $this->assertStringNotContainsString('aria-invalid', $markup);
    $this->assertStringContainsString('max="400"', $this->ajaxMarkup($response, $this->wrapperSelector($container['capacity'])));

    // Valid and unmarked: the license is left where it is.
    $state = $this->postStep(3, $surface, $trigger);
    $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY];
    $this->assertNotContains($this->wrapperSelector($container['third_party_settings'][self::MODULE]['license']), $this->ajaxSelectors($this->ajaxResponse($state), 'replaceWith'));
  }

  /**
   * Tests a full submission refuses both, each in the resource's words.
   */
  public function testFullSubmissionRefusesBothInTheResourceWords(): void {
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
      'third_party_settings' => [self::MODULE => ['license' => 'EV-2048', 'stewards' => '3']],
    ];
    $state = $this->postStep(3, $surface, ['op' => 'Save']);
    $errors = array_map('strval', $state->getErrors());
    $this->assertSame('Event license: A license number is four digits, such as 2048.', $errors['surface][third_party_settings][' . self::MODULE . '][license'] ?? NULL);
    $this->assertArrayHasKey('surface][capacity', $errors);
    $this->assertStringContainsString('100', $errors['surface][capacity']);
    foreach ($errors as $error) {
      $this->assertStringNotContainsString('is not in the right format', $error);
    }
    $this->assertSame(50, $this->config('surface_examples.registration_step3')->get('capacity'));
  }

  /**
   * Tests the discard cascade drops one mounted key, not the mount.
   *
   * A new venue orphans the stored room, which drops the capacity
   * answered under it, which drops the stewards count answered under
   * that capacity; the license beside it is no target and stands.
   */
  public function testTheDiscardCascadeDropsOnlyTheStewards(): void {
    $stored = $this->config('surface_examples.registration_step3')->get();
    $input = [
      'venue' => 'riverside',
      'room' => 'library_reading',
      'capacity' => 80,
    ] + self::compliance('2048', 2) + $stored;
    $surface = $this->surface();
    $builder = $this->formBuilder();
    $this->assertSame(['room', 'capacity', self::STEWARDS], $builder->discardedRefinementInput($surface, $stored, $input));
    $overlay = $builder->refinementOverlay($surface, $stored, $input);
    $this->assertSame(['license' => '2048'], $overlay['third_party_settings'][self::MODULE]);
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
