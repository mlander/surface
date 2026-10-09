<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;
use Drupal\data_surface_examples\Venues;
use Drupal\data_surface_react\Controller\SurfaceApiController;
use Drupal\data_surface_surface_test\Surface\Crate\CrateSurface;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the served contract the main module emits, over five surfaces.
 *
 * Examples 1 to 3, the content type surface in both its situations, and
 * the demo block's surface built the way its host builds it. What is
 * asserted is what a client reads: titles from labels, allowed values as
 * `oneOf` with their labels, bounds, the dependency edges, the slot as a
 * conditional on the deciding key, the locked machine name, and the
 * x-surface reading of each key, widget hints included where the React
 * renderer asks for them and left out otherwise. Every schema is then
 * held to two
 * validators already in the site's vendor directory: opis/json-schema,
 * which compiles it under draft 2020-12 and validates the contract's own
 * values against it (and refuses values the surface refuses), and the
 * draft-07 meta-schema justinrainbow/json-schema ships, against which the
 * schema document itself is checked: every keyword the emitter writes
 * has the same shape in both drafts, and no 2020-12 meta-schema is
 * vendored.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedContractTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_demo',
    'data_surface_demo_node_type',
    'data_surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Emits the contract of one situation, as a client decodes it.
   *
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   * @param array $parameters
   *   The situation's parameters.
   * @param array $values
   *   Values over what the situation is served with.
   * @param bool $widgets
   *   Whether to ask for the widget hints, as the React renderer does.
   *
   * @return array
   *   The contract, through JSON and back.
   */
  protected function contract(string $surface, string $situation, array $parameters = [], array $values = [], bool $widgets = TRUE): array {
    $served = $this->container->get('data_surface_react.served_situations');
    $situation = $served->resolve($surface, $situation, $parameters);
    $contract = $this->container->get('data_surface.contract_emitter')->emit(
      $situation->surface,
      array_replace($served->current($situation), $values),
      $situation->definition->id,
      $situation->situation->id,
      $situation->situation->label,
      $widgets,
    );
    return json_decode((string) json_encode($contract->document), TRUE);
  }

  /**
   * Reads the titles of a property's allowed values.
   *
   * @param array $property
   *   The property schema.
   *
   * @return array<string, string>
   *   Titles keyed by value, the untitled null entry left out.
   */
  protected function titles(array $property): array {
    $titles = [];
    foreach ($property['oneOf'] ?? [] as $choice) {
      if (array_key_exists('title', $choice)) {
        $titles[(string) $choice['const']] = $choice['title'];
      }
    }
    return $titles;
  }

  /**
   * Finds the `then` schema a slot's conditional gives one variant.
   *
   * @param array $schema
   *   The object schema holding the slot.
   * @param string $by
   *   The deciding key.
   * @param string $value
   *   The deciding value.
   * @param string $slot
   *   The slot key.
   *
   * @return array
   *   The variant's schema.
   */
  protected function variant(array $schema, string $by, string $value, string $slot): array {
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (($condition['if']['properties'][$by]['const'] ?? NULL) === $value && isset($condition['then']['properties'][$slot])) {
        $this->assertSame([$by], $condition['if']['required']);
        return $condition['then']['properties'][$slot];
      }
    }
    $this->fail(sprintf('No conditional gives %s its %s shape.', $slot, $value));
  }

  /**
   * Validates values against a contract's schema with opis/json-schema.
   *
   * @param array $schema
   *   The schema.
   * @param mixed $values
   *   The values.
   *
   * @return bool
   *   Whether they are valid.
   */
  protected function validates(array $schema, mixed $values): bool {
    $validator = new Validator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    return $validator->validate(
      json_decode((string) json_encode($values)),
      (string) json_encode($schema),
    )->isValid();
  }

  /**
   * Holds a schema document to the vendored draft-07 meta-schema.
   *
   * @param array $schema
   *   The schema.
   */
  protected function assertWellFormed(array $schema): void {
    $meta = DRUPAL_ROOT . '/../vendor/justinrainbow/json-schema/dist/schema/json-schema-draft-07.json';
    $this->assertFileExists($meta);
    $validator = new Validator();
    $result = $validator->validate(json_decode((string) json_encode($schema)), (string) file_get_contents($meta));
    $this->assertTrue($result->isValid(), 'The schema is a well-formed schema document.');
  }

  /**
   * Tests example 1: titles, required, bounds, defaults, widgets.
   */
  public function testExampleOne(): void {
    $contract = $this->contract('registration.step1', 'configure');
    $this->assertSame('registration.step1', $contract['surface']);
    $this->assertSame('configure', $contract['situation']);
    $this->assertSame('Configure registration', $contract['label']);
    $schema = $contract['schema'];
    $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
    $this->assertSame('object', $schema['type']);
    $this->assertFalse($schema['additionalProperties']);
    $this->assertSame(['title'], $schema['required']);
    $this->assertSame(['title', 'capacity', 'open'], array_keys($schema['properties']));

    $title = $schema['properties']['title'];
    $this->assertSame('Event title', $title['title']);
    $this->assertSame('string', $title['type']);
    $this->assertSame('text', $title['x-surface']['widget']);

    $capacity = $schema['properties']['capacity'];
    $this->assertSame('Capacity', $capacity['title']);
    $this->assertSame(['integer', 'null'], $capacity['type']);
    $this->assertSame(1, $capacity['minimum']);
    $this->assertSame(1000, $capacity['maximum']);
    $this->assertSame(50, $capacity['default']);
    $this->assertSame('number', $capacity['x-surface']['widget']);
    $this->assertFalse($capacity['x-surface']['refined']);

    $this->assertSame('checkbox', $schema['properties']['open']['x-surface']['widget']);
    $this->assertTrue($schema['properties']['open']['default']);
    $this->assertSame(['title' => 'Spring meetup', 'capacity' => 50, 'open' => TRUE], $contract['values']);
    $this->assertSame([], $contract['stale']);

    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $contract['values']));
    $this->assertFalse($this->validates($schema, ['capacity' => 1001] + $contract['values']));
    $this->assertFalse($this->validates($schema, ['unknown' => 1] + $contract['values']));
  }

  /**
   * Tests example 2: labelled choices, and the venue narrowing the room.
   */
  public function testExampleTwo(): void {
    $contract = $this->contract('registration.step2', 'configure');
    $schema = $contract['schema'];
    $venue = $schema['properties']['venue'];
    $this->assertSame([
      'riverside' => 'Riverside Hall',
      'library' => 'Old Library',
      'harbour' => 'Harbour Centre',
    ], $this->titles($venue));
    $this->assertArrayNotHasKey('enum', $venue);
    $this->assertSame('select', $venue['x-surface']['widget']);
    // Required, and a valid choice is stored: no empty option.
    $this->assertSame(['show' => FALSE, 'label' => '- Select -'], $venue['x-surface']['emptyOption']);
    $this->assertSame([], $venue['x-surface']['dependsOn']);

    // The room depends on the venue and is narrowed to the riverside's.
    $room = $schema['properties']['room'];
    $this->assertSame(['venue'], $room['x-surface']['dependsOn']);
    $this->assertTrue($room['x-surface']['refined']);
    $this->assertSame([
      'riverside_main' => 'Main hall',
      'riverside_east' => 'East room',
    ], $this->titles($room));
    // The capacity depends on the room, and the main hall seats 400.
    $capacity = $schema['properties']['capacity'];
    $this->assertSame(['room'], $capacity['x-surface']['dependsOn']);
    $this->assertTrue($capacity['x-surface']['refined']);
    $this->assertSame(400, $capacity['maximum']);

    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $contract['values']));
    $this->assertFalse($this->validates($schema, ['room' => 'library_reading'] + $contract['values']), 'A room of another venue is refused.');
    $this->assertFalse($this->validates($schema, ['capacity' => 401] + $contract['values']), 'More people than the room seats are refused.');

    // Another venue: its rooms, and the stored room shown stale.
    $moved = $this->contract('registration.step2', 'configure', [], ['venue' => 'library']);
    $room = $moved['schema']['properties']['room'];
    $this->assertSame([
      'library_reading' => 'Reading room',
      'library_garden' => 'Garden room',
    ], $this->titles($room));
    $this->assertTrue($room['x-surface']['stale']);
    $this->assertSame(['show' => TRUE, 'label' => '- Select -'], $room['x-surface']['emptyOption']);
    $this->assertNull($moved['values']['room']);
    $this->assertSame(['room'], $moved['stale']);

    // The garden room seats 30.
    $garden = $this->contract('registration.step2', 'configure', [], ['venue' => 'library', 'room' => 'library_garden']);
    $this->assertSame(30, $garden['schema']['properties']['capacity']['maximum']);
  }

  /**
   * Tests a refinement overlay is read as the form reads it.
   *
   * Refined against the keys as they stand, each orphan held unanswered;
   * each orphan shown stale, standing for its stored value, whatever its
   * widget, and the value itself never handed over.
   */
  public function testRefinementOverlay(): void {
    $served = $this->container->get('data_surface_react.served_situations');
    $situation = $served->resolve('registration.step2', 'configure', []);
    $overlay = $this->container->get('data_surface.form_builder')->refinementOverlay(
      $situation->surface,
      $served->current($situation),
      ['venue' => 'library', 'room' => 'riverside_main'],
    );
    $this->assertSame(['room' => 'riverside_main'], $overlay[DataSurfaceFormBuilderInterface::STANDING_KEY]);
    $contract = $this->contract('registration.step2', 'configure', [], $overlay);
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::STANDING_KEY, $contract['values']);
    $room = $contract['schema']['properties']['room'];
    $this->assertTrue($room['x-surface']['stale']);
    $this->assertSame(['show' => TRUE, 'label' => '- Select -'], $room['x-surface']['emptyOption']);
    $this->assertNull($contract['values']['room']);
    $this->assertSame(['room'], $contract['stale']);
    // Nothing caps the capacity by a room no longer on the screen.
    $capacity = $contract['schema']['properties']['capacity'];
    $this->assertSame(1000, $capacity['maximum']);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertFalse($capacity['x-surface']['refined']);
    $this->assertSame(50, $contract['values']['capacity']);

    // A number standing for a stored value is stale too, and shown as
    // nothing: there is no option list to fall out of, only the overlay's
    // word for it.
    $number = $this->contract('registration.step1', 'configure', [], [
      'capacity' => NULL,
      DataSurfaceFormBuilderInterface::STANDING_KEY => ['capacity' => 70],
    ]);
    $this->assertTrue($number['schema']['properties']['capacity']['x-surface']['stale']);
    $this->assertArrayNotHasKey('emptyOption', $number['schema']['properties']['capacity']['x-surface']);
    $this->assertNull($number['values']['capacity']);
    $this->assertSame(['capacity'], $number['stale']);
  }

  /**
   * Tests example 3: the ticket slot's conditional, and the contact part.
   */
  public function testExampleThree(): void {
    $contract = $this->contract('registration.step3', 'configure');
    $schema = $contract['schema'];
    $ticket = $schema['properties']['ticket'];
    $this->assertSame('Ticket', $ticket['title']);
    $this->assertSame('slot', $ticket['x-surface']['widget']);
    $this->assertSame('pricing', $ticket['x-surface']['by']);
    $this->assertSame(['free', 'paid'], $ticket['x-surface']['variants']);
    $this->assertSame('free', $ticket['x-surface']['chosen']);
    $this->assertSame(['pricing'], $ticket['x-surface']['dependsOn']);
    $this->assertArrayNotHasKey('properties', $ticket, 'The slot itself fixes no shape; the conditionals do.');

    $free = $this->variant($schema, 'pricing', 'free', 'ticket');
    $this->assertSame(['note'], array_keys($free['properties']));
    $this->assertSame('fieldset', $free['x-surface']['widget']);
    $paid = $this->variant($schema, 'pricing', 'paid', 'ticket');
    $this->assertSame(['price', 'currency'], $paid['required']);
    $this->assertSame(0.01, $paid['properties']['price']['minimum']);
    $this->assertSame(['EUR' => 'EUR', 'GBP' => 'GBP', 'USD' => 'USD'], $this->titles($paid['properties']['currency']));
    $this->assertSame('EUR', $paid['properties']['currency']['default']);

    $contact = $schema['properties']['contact'];
    $this->assertSame('Contact', $contact['title']);
    $this->assertSame('fieldset', $contact['x-surface']['widget']);
    $this->assertSame(['email'], $contact['required']);
    $this->assertSame('email', $contact['properties']['email']['format']);
    $this->assertSame('email', $contact['properties']['email']['x-surface']['widget']);

    $this->assertSame(['note' => ''], $contract['values']['ticket']);
    $this->assertSame(['email' => 'events@example.com', 'phone' => ''], $contract['values']['contact']);

    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $contract['values']));
    $paid_values = ['pricing' => 'paid', 'ticket' => ['price' => 12.5, 'currency' => 'EUR']] + $contract['values'];
    $this->assertTrue($this->validates($schema, $paid_values), 'A paid ticket fits the paid conditional.');
    $this->assertFalse($this->validates($schema, ['pricing' => 'paid'] + $contract['values']), 'A free ticket does not fit the paid conditional.');
    $this->assertFalse($this->validates($schema, ['contact' => ['email' => 'nobody', 'phone' => '']] + $contract['values']));

    // Choosing paid resolves the slot to the paid ticket, from its defaults.
    $chosen = $this->contract('registration.step3', 'configure', [], ['pricing' => 'paid']);
    $this->assertSame('paid', $chosen['schema']['properties']['ticket']['x-surface']['chosen']);
    $this->assertSame(['price' => NULL, 'currency' => 'EUR'], $chosen['values']['ticket']);
  }

  /**
   * Collects every `x-surface` keyword in a schema, by where it sits.
   *
   * @param array $schema
   *   The schema.
   * @param string $at
   *   Where the schema sits, as a JSON pointer.
   *
   * @return array<string, array>
   *   Each keyword, keyed by the pointer of the schema carrying it.
   */
  protected function extensions(array $schema, string $at = ''): array {
    $found = isset($schema['x-surface']) ? [$at => $schema['x-surface']] : [];
    foreach ($schema as $key => $value) {
      if ($key !== 'x-surface' && is_array($value)) {
        $found += $this->extensions($value, $at . '/' . $key);
      }
    }
    return $found;
  }

  /**
   * Tests the widget hints are off by default and on for the React app.
   *
   * The canonical contract names no widget; everything else under
   * `x-surface` is the surface's own reading and stays. The React
   * submodule's endpoint asks the same service for the hints.
   */
  public function testWidgetHintsAreTheRenderersAlone(): void {
    $surface = $this->container->get('data_surface.surfaces')->build(DemoBlockSurface::class, new SurfaceContext('configure'));
    $default = $this->container->get('data_surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.data_surface_demo')
      ->document;
    $plain = json_decode((string) json_encode($default['schema']), TRUE);
    $this->assertSame($plain, $this->contractOf($surface, FALSE));
    $this->assertNotSame($plain, $this->contractOf($surface, TRUE));

    $canonical = $this->contract('registration.step3', 'configure', widgets: FALSE);
    $schema = $canonical['schema'];
    $extensions = $this->extensions($schema);
    $this->assertNotEmpty($extensions);
    foreach ($extensions as $at => $extension) {
      $this->assertArrayNotHasKey('widget', $extension, $at . ' names no widget.');
      $this->assertArrayNotHasKey('multiple', $extension, $at . ' names no widget.');
      $this->assertSame(['locked', 'dependsOn', 'refined', 'stale'], array_slice(array_keys($extension), 0, 4), $at . ' keeps the surface\'s own reading.');
    }
    $ticket = $schema['properties']['ticket']['x-surface'];
    $this->assertSame('pricing', $ticket['by']);
    $this->assertSame(['free', 'paid'], $ticket['variants']);
    $this->assertSame('free', $ticket['chosen']);
    $this->assertSame(['venue'], $schema['properties']['room']['x-surface']['dependsOn']);
    $this->assertArrayHasKey('emptyOption', $schema['properties']['venue']['x-surface']);
    $this->assertSame('paid', $this->variant($schema, 'pricing', 'paid', 'ticket')['x-surface']['variant']);
    // The hints are the only difference.
    $hinted = $this->contract('registration.step3', 'configure');
    $this->assertSame(array_keys($this->extensions($hinted['schema'])), array_keys($extensions));
    $this->assertSame($canonical['values'], $hinted['values']);
    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $canonical['values']));

    // The React endpoint asks for them.
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(SurfaceApiController::class);
    $served = json_decode((string) $controller->contract(Request::create('/surface-api/registration.step3/configure'), 'registration.step3', 'configure')->getContent(), TRUE);
    $this->assertSame('slot', $served['schema']['properties']['ticket']['x-surface']['widget']);
    $this->assertSame('select', $served['schema']['properties']['venue']['x-surface']['widget']);
    $this->assertSame('fieldset', $this->variant($served['schema'], 'pricing', 'paid', 'ticket')['x-surface']['widget']);
    $this->assertSame($hinted['schema'], $served['schema']);
  }

  /**
   * Emits a surface asked for in no situation, its schema decoded.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param bool $widgets
   *   Whether to ask for the widget hints.
   *
   * @return array
   *   The schema, through JSON and back.
   */
  protected function contractOf(DataSurfaceInterface $surface, bool $widgets): array {
    $document = $this->container->get('data_surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.data_surface_demo', NULL, NULL, $widgets)
      ->document;
    return json_decode((string) json_encode($document['schema']), TRUE);
  }

  /**
   * Tests example 4 on example 3: the capacity depends on the licence.
   *
   * The compliance alter's method on the capacity watches the licence the
   * alter mounted, so the capacity's dependsOn names the licence by its
   * path in the frame, and a contract refined with a licence lifts the
   * hundred back to the room's limit.
   */
  public function testExampleFourWatchesTheLicence(): void {
    $this->enableModules(['data_surface_examples_compliance']);
    $licence = 'third_party_settings.data_surface_examples_compliance.licence';
    $room = ['venue' => 'riverside', 'room' => 'riverside_main'];
    $contract = $this->contract('registration.step3', 'configure', [], $room);
    $capacity = $contract['schema']['properties']['capacity'];
    $this->assertSame(['room', $licence], $capacity['x-surface']['dependsOn']);
    $this->assertSame(100, $capacity['maximum']);
    $this->assertSame('Up to 100 without an event licence. With one, up to 400.', $capacity['description']);
    $this->assertSame(['capacity'], $contract['schema']['properties']['third_party_settings']['x-surface']['dependsOn']);
    // The mount only groups each module's fieldset: no title of its own,
    // and the module's map titled by its alter.
    $mount = $contract['schema']['properties']['third_party_settings'];
    $this->assertArrayNotHasKey('title', $mount);
    $this->assertArrayNotHasKey('description', $mount);
    $this->assertTrue($mount['x-surface']['group']);
    $this->assertSame('Compliance', $mount['properties']['data_surface_examples_compliance']['title']);

    $licensed = $this->contract('registration.step3', 'configure', [], $room + [
      'capacity' => 150,
      'third_party_settings' => ['data_surface_examples_compliance' => ['licence' => '2048', 'stewards' => 3]],
    ]);
    $capacity = $licensed['schema']['properties']['capacity'];
    $this->assertSame(400, $capacity['maximum']);
    $this->assertSame('Up to 400 for the Main hall.', $capacity['description']);
    $this->assertWellFormed($licensed['schema']);
    $this->assertTrue($this->validates($licensed['schema'], $licensed['values']));
  }

  /**
   * Emits a surface's static contract: described for no values at all.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param string $id
   *   Its id.
   *
   * @return array
   *   The contract, through JSON and back, and what it depends on.
   */
  protected function staticContract(DataSurfaceInterface $surface, string $id): array {
    $served = $this->container->get('data_surface.contract_emitter')->emit($surface, [], $id);
    return [json_decode((string) json_encode($served->document), TRUE), $served->getCacheTags()];
  }

  /**
   * Builds the surface one situation serves.
   *
   * @param string $surface
   *   The surface id.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface, built in its configure situation.
   */
  protected function situationSurface(string $surface): DataSurfaceInterface {
    return $this->container->get('data_surface_react.served_situations')->resolve($surface, 'configure', [])->surface;
  }

  /**
   * Collects the conditionals that enumerate one key, in order.
   *
   * @param array $schema
   *   The object schema holding the key.
   * @param string $key
   *   The key.
   *
   * @return array<int, array{0: array, 1: array}>
   *   Each conditional's watched values, by key, and what its `then` says
   *   about the key.
   */
  protected function branches(array $schema, string $key): array {
    $branches = [];
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (!isset($condition['then']['properties'][$key])) {
        continue;
      }
      $when = array_map(static fn (array $value): mixed => $value['const'], $condition['if']['properties']);
      $this->assertSame(array_keys($when), $condition['if']['required']);
      $branches[] = [$when, $condition['then']['properties'][$key]];
    }
    return $branches;
  }

  /**
   * Strips `x-surface` from a schema, at every depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The schema in plain JSON Schema.
   */
  protected function plain(array $schema): array {
    unset($schema['x-surface']);
    foreach ($schema as $keyword => $value) {
      if (is_array($value)) {
        $schema[$keyword] = $this->plain($value);
      }
    }
    return $schema;
  }

  /**
   * Applies what a conditional says about a key to its unrefined schema.
   *
   * @param array $base
   *   The unrefined schema.
   * @param array $narrowed
   *   What the conditional's `then` says about the key.
   *
   * @return array
   *   The schema a validator holds the key to under the conditional.
   */
  protected function applied(array $base, array $narrowed): array {
    foreach ($narrowed as $keyword => $value) {
      if ($keyword === 'properties') {
        foreach ($value as $name => $property) {
          $base['properties'][$name] = $this->applied($base['properties'][$name] ?? [], $property);
        }
        continue;
      }
      $base[$keyword] = $value;
    }
    return $base;
  }

  /**
   * Tests example 2's static contract is exact, with no round trip.
   *
   * Nothing chosen, so nothing is refined: the room lists every room and
   * the capacity allows a thousand, and beside them the schema states, per
   * venue, its rooms and, per room, how many it seats. A validator holding
   * values to it alone refuses what the server refuses.
   */
  public function testStaticExampleTwoIsExact(): void {
    [$contract] = $this->staticContract($this->situationSurface('registration.step2'), 'registration.step2');
    $schema = $contract['schema'];
    $this->assertWellFormed($schema);

    $room = $schema['properties']['room'];
    $this->assertFalse($room['x-surface']['refined']);
    $this->assertTrue($room['x-surface']['enumerated']);
    $this->assertArrayNotHasKey('dynamic', $room['x-surface']);
    $this->assertSame(Venues::rooms(), $this->titles($room));
    $rooms = [];
    foreach ($this->branches($schema, 'room') as [$when, $then]) {
      $this->assertSame(['oneOf'], array_keys($then), 'A conditional says only what changed.');
      $rooms[$when['venue']] = $this->titles($then);
    }
    $this->assertSame(array_map(Venues::rooms(...), array_combine(array_keys(Venues::VENUES), array_keys(Venues::VENUES))), $rooms);

    $capacity = $schema['properties']['capacity'];
    $this->assertSame(1000, $capacity['maximum']);
    $this->assertTrue($capacity['x-surface']['enumerated']);
    $capacities = [];
    foreach ($this->branches($schema, 'capacity') as [$when, $then]) {
      $capacities[$when['room']] = $then;
    }
    $this->assertSame(array_keys(Venues::ROOMS), array_keys($capacities));
    $this->assertEquals(['description' => 'Up to 400 for the Main hall.', 'maximum' => 400], $capacities['riverside_main']);
    $this->assertArrayNotHasKey('enumerated', $schema['properties']['venue']['x-surface']);

    $valid = [
      'title' => 'Spring meetup',
      'open' => TRUE,
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'capacity' => 400,
    ];
    $this->assertTrue($this->validates($schema, $valid));
    $this->assertFalse($this->validates($schema, ['room' => 'library_reading'] + $valid), 'Another venue\'s room is refused.');
    $this->assertFalse($this->validates($schema, ['capacity' => 500] + $valid), 'More than the room seats is refused.');
    foreach (Venues::ROOMS as $name => $info) {
      $values = ['venue' => $info['venue'], 'room' => $name, 'capacity' => $info['seats']] + $valid;
      $this->assertTrue($this->validates($schema, $values), $name . ' at its seats is accepted.');
      $this->assertFalse($this->validates($schema, ['capacity' => $info['seats'] + 1] + $values), $name . ' over its seats is refused.');
      $elsewhere = $info['venue'] === 'harbour' ? 'riverside' : 'harbour';
      $this->assertFalse($this->validates($schema, ['venue' => $elsewhere] + $values), $name . ' under another venue is refused.');
    }

    // With values, as the GET serves it and /refine re-narrows it, the
    // keys are refined and nothing is enumerated.
    $refined = $this->contract('registration.step2', 'configure')['schema'];
    $this->assertArrayNotHasKey('allOf', $refined);
    foreach ($refined['properties'] as $name => $property) {
      $this->assertArrayNotHasKey('enumerated', $property['x-surface'], $name);
      $this->assertArrayNotHasKey('dynamic', $property['x-surface'], $name);
    }
  }

  /**
   * Tests every enumerated conditional is what refinement answers.
   *
   * Example 2's against /refine itself, sent the conditional's values;
   * the demo block's, which no route serves, against the contract emitted
   * for them. Each conditional applied to the unrefined key is the key
   * the refined contract describes, keyword for keyword.
   */
  public function testEnumeratedConditionalsAreWhatRefineAnswers(): void {
    [$contract] = $this->staticContract($this->situationSurface('registration.step2'), 'registration.step2');
    $static = $contract['schema'];
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(SurfaceApiController::class);
    $compared = 0;
    foreach (['room', 'capacity'] as $key) {
      foreach ($this->branches($static, $key) as [$when, $then]) {
        // A room is sent with its own venue, which /refine needs to admit it.
        $values = $when + (isset($when['room']) ? ['venue' => Venues::ROOMS[$when['room']]['venue']] : []);
        $body = (string) json_encode(['values' => $values, 'stale' => []]);
        $request = Request::create('/surface-api/registration.step2/configure/refine', 'POST', content: $body);
        $answer = json_decode((string) $controller->refine($request, 'registration.step2', 'configure')->getContent(), TRUE);
        $refined = $answer['schema']['properties'][$key];
        $this->assertTrue($refined['x-surface']['refined']);
        $this->assertEquals($this->plain($refined), $this->applied($this->plain($static['properties'][$key]), $then), $key . ' for ' . json_encode($when));
        $compared++;
      }
    }
    $this->assertSame(9, $compared, 'Three venues and six rooms.');

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $surface = $this->container->get('data_surface.surfaces')->build(DemoBlockSurface::class, new SurfaceContext('configure'));
    [$contract, $tags] = $this->staticContract($surface, 'block.data_surface_demo');
    $static = $contract['schema'];
    $this->assertWellFormed($static);
    // The bundle lists the conditionals read are what the contract rests on.
    $this->assertContains('entity_bundles', $tags);
    $this->assertTrue($static['properties']['bundle']['x-surface']['enumerated']);
    $this->assertTrue($static['properties']['field']['x-surface']['enumerated']);
    $pairs = [];
    foreach (['bundle', 'field'] as $key) {
      foreach ($this->branches($static, $key) as [$when, $then]) {
        $document = $this->container->get('data_surface.contract_emitter')->emit($surface, $when, 'block.data_surface_demo')->document;
        $refined = json_decode((string) json_encode($document['schema']['properties'][$key]), TRUE);
        $this->assertTrue($refined['x-surface']['refined']);
        $this->assertEquals($this->plain($refined), $this->applied($this->plain($static['properties'][$key]), $then), $key . ' for ' . json_encode($when));
        if ($key === 'field') {
          $this->assertSame(['entity_type', 'bundle'], array_keys($when), 'The field is enumerated over both siblings it watches.');
          $pairs[] = $when['entity_type'] . '.' . $when['bundle'];
        }
      }
    }
    $this->assertContains('user.user', $pairs);
    $this->assertContains('node.article', $pairs);
  }

  /**
   * Tests example 4's refiners stay dynamic: their answers are not finite.
   *
   * The capacity watches the licence, free text, and the stewards (inside
   * the mount) watch the capacity, a number; neither can be listed, so
   * both are marked dynamic, to be asked again, while the room, which
   * watches only the venue, is still enumerated.
   */
  public function testExampleFourStaysDynamic(): void {
    $this->enableModules(['data_surface_examples_compliance']);
    [$contract] = $this->staticContract($this->situationSurface('registration.step3'), 'registration.step3');
    $schema = $contract['schema'];
    $this->assertWellFormed($schema);
    $capacity = $schema['properties']['capacity']['x-surface'];
    $this->assertTrue($capacity['dynamic']);
    $this->assertArrayNotHasKey('enumerated', $capacity);
    $this->assertSame(['room', 'third_party_settings.data_surface_examples_compliance.licence'], $capacity['dependsOn']);
    $this->assertSame([], $this->branches($schema, 'capacity'));
    $mount = $schema['properties']['third_party_settings']['x-surface'];
    $this->assertTrue($mount['dynamic']);
    $this->assertSame(['capacity'], $mount['dependsOn']);
    $this->assertTrue($schema['properties']['room']['x-surface']['enumerated']);
    $this->assertCount(3, $this->branches($schema, 'room'));
  }

  /**
   * Tests slots keep their conditionals, and a variant enumerates inside.
   */
  public function testSlotVariantsEnumerateInsideTheirConditional(): void {
    [$contract] = $this->staticContract($this->situationSurface('registration.step3'), 'registration.step3');
    $schema = $contract['schema'];
    $this->assertSame(['note'], array_keys($this->variant($schema, 'pricing', 'free', 'ticket')['properties']));
    $this->assertSame(['price', 'currency'], $this->variant($schema, 'pricing', 'paid', 'ticket')['required']);
    $this->assertCount(3, $this->branches($schema, 'room'));
    $this->assertCount(6, $this->branches($schema, 'capacity'));

    // The fruit has no default, so the crate's layers are enumerated in
    // the fruit variant's own schema, under its conditional.
    $this->enableModules(['data_surface_surface_test']);
    $surface = $this->container->get('data_surface.surfaces')->build(CrateSurface::class, new SurfaceContext('configure'));
    [$contract] = $this->staticContract($surface, 'surface_test.crate');
    $schema = $contract['schema'];
    $this->assertWellFormed($schema);
    $fruit = $this->variant($schema, 'contents', 'fruit', 'contents_settings');
    $this->assertTrue($fruit['properties']['layers']['x-surface']['enumerated']);
    $layers = [];
    foreach ($this->branches($fruit, 'layers') as [$when, $then]) {
      $layers[$when['fruit']] = $then;
    }
    $this->assertSame(['apple' => ['maximum' => 4], 'peach' => ['maximum' => 2]], $layers);
    $crate = ['contents' => 'fruit', 'contents_settings' => ['fruit' => 'apple', 'layers' => 4]];
    $this->assertTrue($this->validates($schema, $crate));
    $this->assertFalse($this->validates($schema, ['contents_settings' => ['fruit' => 'peach', 'layers' => 4]] + $crate));
    $this->assertTrue($this->validates($schema, ['contents_settings' => ['fruit' => 'peach', 'layers' => 2]] + $crate));
  }

  /**
   * Tests the content type surface: locked on edit, open on add.
   */
  public function testNodeType(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $edit = $this->contract('node.type', 'edit', ['type' => 'article']);
    $schema = $edit['schema'];
    $type = $schema['properties']['type'];
    $this->assertTrue($type['x-surface']['locked']);
    $this->assertTrue($type['readOnly']);
    $this->assertSame('article', $type['const']);
    $this->assertSame('article', $edit['values']['type']);
    $this->assertSame('^[a-z0-9_]+$', $type['pattern']);
    $this->assertSame(255, $schema['properties']['name']['maxLength']);
    $this->assertSame('Article', $edit['values']['name']);
    $this->assertSame('textarea', $schema['properties']['description']['x-surface']['widget']);
    $preview = $schema['properties']['preview_mode'];
    $this->assertSame([0, 1, 2], array_column(array_filter($preview['oneOf'], fn (array $choice): bool => isset($choice['title'])), 'const'));
    $this->assertSame('integer', $preview['type']);

    $add = $this->contract('node.type', 'add');
    $type = $add['schema']['properties']['type'];
    $this->assertFalse($type['x-surface']['locked']);
    $this->assertArrayNotHasKey('readOnly', $type);
    $this->assertContains('DataSurfaceUniqueNodeType', $type['x-surface']['checkedOnServer']);
  }

  /**
   * Tests the demo block's surface, built as its host builds it.
   */
  public function testDemoBlock(): void {
    $surface = $this->container->get('data_surface.surfaces')->build(DemoBlockSurface::class, new SurfaceContext('configure'));
    $document = $this->container->get('data_surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.data_surface_demo', widgets: TRUE)
      ->document;
    $contract = json_decode((string) json_encode($document), TRUE);
    $this->assertNull($contract['situation']);
    $schema = $contract['schema'];
    $headline = $schema['properties']['headline'];
    $this->assertSame(50, $headline['maxLength']);
    $this->assertSame(['Quarterly report'], $headline['examples']);
    $this->assertArrayHasKey('user', $this->titles($schema['properties']['entity_type']));
    $this->assertSame(['entity_type'], $schema['properties']['bundle']['x-surface']['dependsOn']);
    $this->assertSame(['entity_type', 'bundle'], $schema['properties']['field']['x-surface']['dependsOn']);
    $this->assertSame(['integer'], (array) $schema['properties']['limit']['type']);
    $slot = $schema['properties']['presentation_settings'];
    $this->assertSame('slot', $slot['x-surface']['widget']);
    $this->assertSame('list', $slot['x-surface']['chosen']);
    $grid = $this->variant($schema, 'presentation', 'grid', 'presentation_settings');
    $this->assertSame(1, $grid['properties']['columns']['minimum']);
    $this->assertSame(6, $grid['properties']['columns']['maximum']);
    $this->assertSame(['show_summary' => TRUE], $contract['values']['presentation_settings']);
  }

  /**
   * Tests the two schemas with string lengths and patterns validate.
   *
   * Apart from the tests above for one reason that is not this module's:
   * opis/json-schema checks a length or a pattern through its own string
   * class, whose ArrayAccess methods Symfony's debug class loader reports
   * as a deprecation the first time it loads. Ignored here, and only
   * here, so the tests that read the contract itself still report any
   * deprecation of ours.
   */
  #[IgnoreDeprecations]
  public function testSchemasWithLengthsValidate(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $edit = $this->contract('node.type', 'edit', ['type' => 'article']);
    $this->assertWellFormed($edit['schema']);
    $this->assertTrue($this->validates($edit['schema'], $edit['values']));
    $this->assertFalse($this->validates($edit['schema'], ['type' => 'page'] + $edit['values']), 'The locked machine name holds one value.');
    $this->assertFalse($this->validates($edit['schema'], ['name' => str_repeat('a', 256)] + $edit['values']));
    $this->assertWellFormed($this->contract('node.type', 'add')['schema']);

    $surface = $this->container->get('data_surface.surfaces')->build(DemoBlockSurface::class, new SurfaceContext('configure'));
    $document = $this->container->get('data_surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.data_surface_demo', widgets: TRUE)
      ->document;
    $contract = json_decode((string) json_encode($document), TRUE);
    $this->assertWellFormed($contract['schema']);
    $this->assertTrue($this->validates($contract['schema'], $contract['values']));
    $this->assertFalse($this->validates($contract['schema'], ['headline' => str_repeat('a', 51)] + $contract['values']));
  }

}
