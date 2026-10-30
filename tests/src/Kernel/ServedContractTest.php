<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Pipeline\TypeMismatchException;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Attachment;
use Drupal\surface_examples_plugins\Structure\DemoBlockStructure;
use Drupal\surface_examples\Venues;
use Drupal\surface_resource_test\Structure\Crate\CrateStructure;
use Drupal\surface_resource_test\Structure\Cupboard\CupboardStructure;
use Drupal\surface_resource_test\Resource\Pantry\PantryResource;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the served contract the main module emits, over five builds.
 *
 * Examples 1 to 3, the content type resource in both its operations, and
 * the demo block's structure built the way its host builds it. What is
 * asserted is what a client reads: titles from labels, allowed values as
 * `oneOf` with their labels, bounds, the dependency edges, the variants as a
 * conditional on the deciding key, the locked machine name, and the
 * x-surface reading of each key, widget hints included where the React
 * renderer asks for them and left out otherwise. Every schema is then
 * held to two
 * validators already in the site's vendor directory: opis/json-schema,
 * which compiles it under draft 2020-12 and validates the contract's own
 * values against it (and refuses values the pipeline refuses), and the
 * draft-07 meta-schema justinrainbow/json-schema ships, against which the
 * schema document itself is checked: every keyword the emitter writes
 * has the same shape in both drafts, and no 2020-12 meta-schema is
 * vendored.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ServedContractTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;

  use ContractValidationTrait;
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
    'surface',
    'surface_tool',
    'surface_examples',
    'surface_examples_plugins',
    'surface_examples_node_type',
    'surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Emits the contract of one operation, as a client decodes it.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $parameters
   *   The operation's parameters.
   * @param array $values
   *   Values over what the operation is served with.
   * @param bool $widgets
   *   Whether to ask for the widget hints, as the React renderer does.
   *
   * @return array
   *   The contract, through JSON and back.
   */
  protected function contract(string $resource, string $operation, array $parameters = [], array $values = [], bool $widgets = TRUE): array {
    $served = $this->container->get('surface_react.served_operations');
    $operation = $served->resolve($resource, $operation, $parameters);
    $contract = $this->container->get('surface.contract_emitter')->emit(
      $operation->surface,
      array_replace($served->current($operation), $values),
      $operation->definition->id,
      $operation->operation->id,
      $operation->operation->label,
      $widgets,
      $operation->operation->facts(),
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
   * Finds what the variants' conditional says of their key for one variant.
   *
   * @param array $schema
   *   The object schema holding the key with variants.
   * @param string $by
   *   The deciding key.
   * @param string $value
   *   The deciding value.
   * @param string $key
   *   The key the variants sit at.
   *
   * @return array
   *   The `then` schema of the key, as written: a reference to the
   *   variant's def, beside the key's label.
   */
  protected function conditional(array $schema, string $by, string $value, string $key): array {
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (($condition['if']['properties'][$by]['const'] ?? NULL) === $value && isset($condition['then']['properties'][$key])) {
        $this->assertSame([$by], $condition['if']['required']);
        return $condition['then']['properties'][$key];
      }
    }
    $this->fail(sprintf('No conditional gives %s its %s shape.', $key, $value));
  }

  /**
   * Finds the shape the variants' conditional gives one variant, inline.
   *
   * @param array $schema
   *   The object schema holding the key with variants.
   * @param string $by
   *   The deciding key.
   * @param string $value
   *   The deciding value.
   * @param string $key
   *   The key the variants sit at.
   * @param array|null $root
   *   The document's root schema, which holds `$defs`, when $schema is
   *   not it.
   *
   * @return array
   *   The variant's effective schema, its references resolved.
   */
  protected function variant(array $schema, string $by, string $value, string $key, ?array $root = NULL): array {
    return SchemaResolver::inline($this->conditional($schema, $by, $value, $key), $root ?? $schema);
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
    $validator = new CompliantValidator();
    $result = $validator->validate(json_decode((string) json_encode($schema)), (string) file_get_contents($meta));
    $this->assertTrue($result->isValid(), 'The schema is a well-formed schema document.');
  }

  /**
   * Tests example 1: titles, required, bounds, defaults, widgets.
   */
  public function testExampleOne(): void {
    $contract = $this->contract('registration.step1', 'configure');
    $this->assertSame('registration.step1', $contract['resource']);
    $this->assertSame('configure', $contract['operation']);
    $this->assertSame('Configure registration (step 1)', $contract['label']);
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
    $this->assertArrayNotHasKey('refined', $capacity['x-surface']);

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
   * Tests example 2: a venue referred to, and the venue narrowing the room.
   */
  public function testExampleTwo(): void {
    $contract = $this->contract('registration.step2', 'configure');
    $schema = $contract['schema'];
    $venue = $schema['properties']['venue'];
    // A reference: the selection handler's candidates, sorted by name.
    $this->assertSame([
      'harbour' => 'Harbour Centre',
      'library' => 'Old Library',
      'riverside' => 'Riverside Hall',
    ], $this->titles($venue));
    $this->assertSame(['entityType' => 'event_venue'], $venue['x-surface']['reference']);
    $this->assertArrayNotHasKey('enum', $venue);
    $this->assertSame('select', $venue['x-surface']['widget']);
    // Required, and a valid choice is stored: no empty option.
    $this->assertSame(['show' => FALSE, 'label' => '- Select -'], $venue['x-surface']['emptyOption']);
    $this->assertArrayNotHasKey('dependsOn', $venue['x-surface']);

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
    // Emitted for these values directly, the stored room is stale and its
    // empty option says it keeps it; /refine holds it as an orphan of the
    // edit instead, which a save refuses, and words it "- Select -".
    $this->assertSame(['show' => TRUE, 'label' => '- Keep riverside_main (no longer offered) -'], $room['x-surface']['emptyOption']);
    $orphaned = $this->contract('registration.step2', 'configure', [], [
      'venue' => 'library',
      SurfaceFormBuilderInterface::STANDING_KEY => ['room' => 'riverside_main'],
      'room' => NULL,
    ]);
    $this->assertSame(['show' => TRUE, 'label' => '- Select -'], $orphaned['schema']['properties']['room']['x-surface']['emptyOption']);
    $this->assertNull($moved['values']['room']);
    $this->assertSame(['/room'], $moved['stale']);

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
    $served = $this->container->get('surface_react.served_operations');
    $operation = $served->resolve('registration.step2', 'configure', []);
    $overlay = $this->container->get('surface.form_builder')->refinementOverlay(
      $operation->surface,
      $served->current($operation),
      ['venue' => 'library', 'room' => 'riverside_main'],
    );
    $this->assertSame(['room' => 'riverside_main'], $overlay[SurfaceFormBuilderInterface::STANDING_KEY]);
    $contract = $this->contract('registration.step2', 'configure', [], $overlay);
    $this->assertArrayNotHasKey(SurfaceFormBuilderInterface::STANDING_KEY, $contract['values']);
    $room = $contract['schema']['properties']['room'];
    $this->assertTrue($room['x-surface']['stale']);
    $this->assertSame(['show' => TRUE, 'label' => '- Select -'], $room['x-surface']['emptyOption']);
    $this->assertNull($contract['values']['room']);
    $this->assertSame(['/room'], $contract['stale']);
    // Nothing caps the capacity by a room no longer on the screen.
    $capacity = $contract['schema']['properties']['capacity'];
    $this->assertSame(1, $capacity['minimum']);
    $this->assertArrayNotHasKey('maximum', $capacity);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertArrayNotHasKey('refined', $capacity['x-surface']);
    $this->assertSame(50, $contract['values']['capacity']);

    // A number standing for a stored value is stale too, and shown as
    // nothing: there is no option list to fall out of, only the overlay's
    // word for it.
    $number = $this->contract('registration.step1', 'configure', [], [
      'capacity' => NULL,
      SurfaceFormBuilderInterface::STANDING_KEY => ['capacity' => 70],
    ]);
    $this->assertTrue($number['schema']['properties']['capacity']['x-surface']['stale']);
    $this->assertArrayNotHasKey('emptyOption', $number['schema']['properties']['capacity']['x-surface']);
    $this->assertNull($number['values']['capacity']);
    $this->assertSame(['/capacity'], $number['stale']);
  }

  /**
   * Tests example 3: the ticket variants' conditional, and the contact part.
   */
  public function testExampleThree(): void {
    $contract = $this->contract('registration.step3', 'configure');
    $schema = $contract['schema'];
    $ticket = $schema['properties']['ticket'];
    $this->assertSame('Ticket', $ticket['title']);
    $this->assertSame('variants', $ticket['x-surface']['widget']);
    $this->assertSame('pricing', $ticket['x-surface']['by']);
    $this->assertSame([
      'free' => '#/$defs/registration.step3.ticket.free',
      'paid' => '#/$defs/registration.step3.ticket.paid',
    ], $ticket['x-surface']['variants'], 'Each value names the def it chooses.');
    $this->assertSame('free', $ticket['x-surface']['chosen']);
    $this->assertSame(['pricing'], $ticket['x-surface']['dependsOn']);
    $this->assertArrayNotHasKey('properties', $ticket, 'The key itself fixes no shape; the conditionals do.');
    $this->assertSame([
      ['$ref' => '#/$defs/registration.step3.ticket.free'],
      ['$ref' => '#/$defs/registration.step3.ticket.paid'],
      ['type' => 'null'],
    ], $ticket['anyOf'], 'The union of its variants, for a type generator.');

    $this->assertArrayNotHasKey('x-surface', $this->conditional($schema, 'pricing', 'free', 'ticket'), 'Nothing below a then carries x-surface.');
    $free = $this->variant($schema, 'pricing', 'free', 'ticket');
    $this->assertSame(['note'], array_keys($free['properties']));
    $paid = $this->variant($schema, 'pricing', 'paid', 'ticket');
    $this->assertSame(['price', 'currency'], $paid['required']);
    $this->assertSame(0.01, $paid['properties']['price']['minimum']);
    $this->assertSame(['EUR' => 'EUR', 'GBP' => 'GBP', 'USD' => 'USD'], $this->titles($paid['properties']['currency']));
    $this->assertSame('EUR', $paid['properties']['currency']['default']);

    // The contact is its own structure: a reference to its def, beside the
    // label the parent gives it.
    $this->assertSame('#/$defs/registration.contact', $schema['properties']['contact']['$ref']);
    $contact = SchemaResolver::inline($schema['properties']['contact'], $schema);
    $this->assertSame('Contact', $contact['title']);
    $this->assertSame('fieldset', $contact['x-surface']['widget']);
    $this->assertSame(['email'], $contact['required']);
    $this->assertSame('idn-email', $contact['properties']['email']['format']);
    $this->assertSame('email', $contact['properties']['email']['x-surface']['widget']);

    $this->assertSame(['note' => ''], $contract['values']['ticket']);
    $this->assertSame(['email' => 'events@example.com', 'phone' => ''], $contract['values']['contact']);

    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $contract['values']));
    $paid_values = ['pricing' => 'paid', 'ticket' => ['price' => 12.5, 'currency' => 'EUR']] + $contract['values'];
    $this->assertTrue($this->validates($schema, $paid_values), 'A paid ticket fits the paid conditional.');
    $this->assertFalse($this->validates($schema, ['pricing' => 'paid'] + $contract['values']), 'A free ticket does not fit the paid conditional.');
    // `format` is advisory: a standard validator does not assert it, and
    // the pipeline's own Email check is the one that refuses this.
    $this->assertTrue($this->validates($schema, ['contact' => ['email' => 'nobody', 'phone' => '']] + $contract['values']));

    // Choosing paid makes the ticket the paid one, from its defaults.
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
    $surface = $this->container->get('surface.resources')->build(DemoBlockStructure::class, new OperationContext('configure'));
    $default = $this->container->get('surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.surface_examples_plugins')
      ->document;
    $plain = json_decode((string) json_encode($default['schema']), TRUE);
    $this->assertSame($plain, $this->contractOf($surface, FALSE));
    $this->assertNotSame($plain, $this->contractOf($surface, TRUE));

    $canonical = $this->contract('registration.step3', 'configure', widgets: FALSE);
    $schema = $canonical['schema'];
    $extensions = $this->extensions($schema);
    $this->assertNotEmpty($extensions);
    foreach ($extensions as $at => $extension) {
      if ($at === '') {
        $this->assertSame(['contract', 'operation', 'version'], array_keys($extension), 'The top states the contract\'s version, how the operation behaves, and the version.');
        continue;
      }
      $this->assertArrayNotHasKey('widget', $extension, $at . ' names no widget.');
      $this->assertArrayNotHasKey('multiple', $extension, $at . ' names no widget.');
      // Written only where it says something: no lock, which `readOnly`
      // says, and no part that would be empty.
      $this->assertNotSame([], $extension, $at . ' says something.');
      $this->assertArrayNotHasKey('locked', $extension, $at);
      foreach (['dependsOn' => [], 'refined' => FALSE, 'stale' => FALSE] as $keyword => $nothing) {
        $this->assertNotSame($nothing, $extension[$keyword] ?? NULL, $at . ' writes no empty ' . $keyword . '.');
      }
    }
    $ticket = $schema['properties']['ticket']['x-surface'];
    $this->assertSame('pricing', $ticket['by']);
    $this->assertSame(['free', 'paid'], array_keys($ticket['variants']));
    $this->assertSame('free', $ticket['chosen']);
    $this->assertSame(['venue'], $schema['properties']['room']['x-surface']['dependsOn']);
    $this->assertArrayHasKey('emptyOption', $schema['properties']['venue']['x-surface']);
    $this->assertSame('#/$defs/registration.step3.ticket.paid', $ticket['variants']['paid']);
    // The hints are the only difference.
    $hinted = $this->contract('registration.step3', 'configure');
    $hints = ['widget' => TRUE, 'multiple' => TRUE, 'arrangement' => TRUE];
    foreach ($this->extensions($hinted['schema']) as $at => $extension) {
      $own = array_diff_key($extension, $hints);
      // An `x-surface` that held only hints is not written without them.
      $this->assertSame($own === [] ? NULL : $own, $extensions[$at] ?? NULL, $at . ' differs by its hints alone.');
      unset($extensions[$at]);
    }
    $this->assertSame([], $extensions, 'No `x-surface` only the canonical document writes.');
    $this->assertSame($canonical['values'], $hinted['values']);
    $this->assertWellFormed($schema);
    $this->assertTrue($this->validates($schema, $canonical['values']));

    // The React endpoint asks for them.
    $served = $this->servedJson('GET', '/surface-api/registration.step3');
    $this->assertSame('variants', $served['schema']['properties']['ticket']['x-surface']['widget']);
    $this->assertSame('select', $served['schema']['properties']['venue']['x-surface']['widget']);
    $this->assertArrayNotHasKey('x-surface', $this->conditional($served['schema'], 'pricing', 'paid', 'ticket'));
    // Served at a route, so it says where: its local references resolve
    // wherever it is embedded.
    $this->assertStringEndsWith('/surface-api/registration.step3', $served['schema']['$id']);
    unset($served['schema']['$id']);
    $this->assertSame($hinted['schema'], $served['schema']);
  }

  /**
   * Emits a data surface asked for in no operation, its schema decoded.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param bool $widgets
   *   Whether to ask for the widget hints.
   *
   * @return array
   *   The schema, through JSON and back.
   */
  protected function contractOf(SurfaceInterface $surface, bool $widgets): array {
    $document = $this->container->get('surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.surface_examples_plugins', NULL, NULL, $widgets)
      ->document;
    return json_decode((string) json_encode($document['schema']), TRUE);
  }

  /**
   * Tests example 4 on example 3: the capacity depends on the license.
   *
   * The compliance alter's rule on the capacity reads the license the
   * alter mounted, so the capacity's dependsOn names the license by its
   * path in the frame, and a contract refined with a license lifts the
   * hundred back to the room's limit.
   */
  public function testExampleFourWatchesTheLicense(): void {
    $this->enableModules(['surface_examples_compliance']);
    $license = 'third_party_settings.surface_examples_compliance.license';
    $room = ['venue' => 'riverside', 'room' => 'riverside_main'];
    $contract = $this->contract('registration.step3', 'configure', [], $room);
    $capacity = $contract['schema']['properties']['capacity'];
    $this->assertSame(['room', $license], $capacity['x-surface']['dependsOn']);
    $this->assertSame(100, $capacity['maximum']);
    $this->assertSame('Up to 100 without an event license.', $capacity['description']);
    $this->assertSame(['capacity'], $contract['schema']['properties']['third_party_settings']['x-surface']['dependsOn']);
    // The mount only groups each module's fieldset: no title of its own,
    // and the module's map titled by its alter.
    $mount = $contract['schema']['properties']['third_party_settings'];
    $this->assertArrayNotHasKey('title', $mount);
    $this->assertArrayNotHasKey('description', $mount);
    $this->assertTrue($mount['x-surface']['group']);
    $this->assertSame('Compliance', $mount['properties']['surface_examples_compliance']['title']);
    // Its alter draws it after the capacity: said on the module's
    // object, and only said. The schema keeps it where its values go,
    // inside the group, the group last.
    $this->assertSame('capacity', $mount['properties']['surface_examples_compliance']['x-surface']['after']);
    $this->assertArrayNotHasKey('after', $mount['x-surface']);
    $this->assertSame([
      'title', 'open', 'venue', 'room', 'capacity', 'pricing', 'ticket', 'contact', 'third_party_settings',
    ], array_keys($contract['schema']['properties']));

    $licensed = $this->contract('registration.step3', 'configure', [], $room + [
      'capacity' => 150,
      'third_party_settings' => ['surface_examples_compliance' => ['license' => '2048', 'stewards' => 3]],
    ]);
    $capacity = $licensed['schema']['properties']['capacity'];
    $this->assertSame(400, $capacity['maximum']);
    $this->assertSame('Up to 400 for the Main hall.', $capacity['description']);
    $this->assertWellFormed($licensed['schema']);
    $this->assertTrue($this->validates($licensed['schema'], $licensed['values']));
  }

  /**
   * Emits a data surface's static contract: described for no values at all.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $id
   *   Its id.
   *
   * @return array
   *   The contract, through JSON and back, and what it depends on.
   */
  protected function staticContract(SurfaceInterface $surface, string $id): array {
    $served = $this->container->get('surface.contract_emitter')->emit($surface, [], $id);
    return [json_decode((string) json_encode($served->document), TRUE), $served->getCacheTags()];
  }

  /**
   * Builds the resource one operation serves.
   *
   * @param string $resource
   *   The resource id.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource, in its configure operation.
   */
  protected function operationSurface(string $resource): SurfaceInterface {
    return $this->container->get('surface_react.served_operations')->resolve($resource, 'configure', [])->surface;
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
    foreach ($schema['allOf'] ?? [] as $i => $condition) {
      if (!isset($condition['then']['properties'][$key]) || in_array($i, $schema['x-surface']['rules'] ?? [], TRUE)) {
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
    [$contract] = $this->staticContract($this->operationSurface('registration.step2'), 'registration.step2');
    $schema = $contract['schema'];
    $this->assertWellFormed($schema);

    $room = $schema['properties']['room'];
    $this->assertArrayNotHasKey('refined', $room['x-surface']);
    $this->assertTrue($room['x-surface']['enumerated']);
    $this->assertArrayNotHasKey('refine', $room['x-surface']);
    $this->assertSame(Venues::rooms(), $this->titles($room));
    $rooms = [];
    foreach ($this->branches($schema, 'room') as [$when, $then]) {
      $this->assertSame(['oneOf'], array_keys($then), 'A conditional says only what changed.');
      $rooms[$when['venue']] = $this->titles($then);
    }
    // One conditional per venue the reference lists, sorted by name.
    $venues = ['harbour', 'library', 'riverside'];
    $this->assertSame(array_map(Venues::rooms(...), array_combine($venues, $venues)), $rooms);

    $capacity = $schema['properties']['capacity'];
    $this->assertArrayNotHasKey('maximum', $capacity, 'Only a room bounds it from above.');
    $this->assertTrue($capacity['x-surface']['enumerated']);
    $capacities = [];
    foreach ($this->branches($schema, 'capacity') as [$when, $then]) {
      $capacities[$when['room']] = $then;
    }
    $this->assertSame(array_keys(Venues::ROOMS), array_keys($capacities));
    // A bound states the declared type beside it, which cannot narrow.
    $this->assertEquals(['type' => $capacity['type'], 'description' => 'Up to 400 for the Main hall.', 'maximum' => 400], $capacities['riverside_main']);
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
      $this->assertArrayNotHasKey('refine', $property['x-surface'], $name);
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
    [$contract] = $this->staticContract($this->operationSurface('registration.step2'), 'registration.step2');
    $static = $contract['schema'];
    $compared = 0;
    foreach (['room', 'capacity'] as $key) {
      foreach ($this->branches($static, $key) as [$when, $then]) {
        // A room is sent with its own venue, which /refine needs to admit it.
        $values = $when + (isset($when['room']) ? ['venue' => Venues::ROOMS[$when['room']]['venue']] : []);
        $answer = $this->servedJson('POST', '/surface-api/registration.step2/schema', $values);
        $refined = $answer['schema']['properties'][$key];
        $this->assertTrue($refined['x-surface']['refined']);
        $this->assertEquals($this->plain($refined), $this->applied($this->plain($static['properties'][$key]), $then), $key . ' for ' . json_encode($when));
        $compared++;
      }
    }
    $this->assertSame(9, $compared, 'Three venues and six rooms.');

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $surface = $this->container->get('surface.resources')->build(DemoBlockStructure::class, new OperationContext('configure'));
    [$contract, $tags] = $this->staticContract($surface, 'block.surface_examples_plugins');
    $static = $contract['schema'];
    $this->assertWellFormed($static);
    // The bundle lists the conditionals read are what the contract rests on.
    $this->assertContains('entity_bundles', $tags);
    $this->assertTrue($static['properties']['bundle']['x-surface']['enumerated']);
    $this->assertTrue($static['properties']['field']['x-surface']['enumerated']);
    $pairs = [];
    foreach (['bundle', 'field'] as $key) {
      foreach ($this->branches($static, $key) as [$when, $then]) {
        $document = $this->container->get('surface.contract_emitter')->emit($surface, $when, 'block.surface_examples_plugins')->document;
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
   * Tests example 4's capacity is exact, and only its stewards dynamic.
   *
   * The capacity watches the room, which offers a list, and its license
   * rule is declared, so both are stated: one conditional per room, and
   * the rule as written, an `if` that the license holds nothing. The
   * stewards (inside the mount) watch the capacity, a number, which cannot
   * be listed: dynamic, to be asked again. The room, which watches only
   * the venue, is still enumerated.
   */
  public function testExampleFourStatesTheCapacityExactly(): void {
    $this->enableModules(['surface_examples_compliance']);
    $schema = $this->full('registration.step3')['schema'];
    $this->assertWellFormed($schema);
    $capacity = $schema['properties']['capacity']['x-surface'];
    $this->assertArrayNotHasKey('refine', $capacity);
    $this->assertTrue($capacity['enumerated']);
    // The frame's rules name the declared rule's conditional (below); the
    // key carries no marker.
    $this->assertArrayNotHasKey('declared', $capacity);
    $this->assertSame(['room', 'third_party_settings.surface_examples_compliance.license'], $capacity['dependsOn']);
    $this->assertCount(6, $this->branches($schema, 'capacity'));
    // Listed by the frame, not marked on the conditional: no x-surface
    // below an applicator.
    $rules = array_values(array_intersect_key($schema['allOf'], array_flip($schema['x-surface']['rules'])));
    $absent = ['type' => 'object', 'properties' => ['license' => ['type' => 'null']]];
    $ceiling = ['type' => ['integer', 'null'], 'description' => 'Up to 100 without an event license.', 'maximum' => 100];
    $this->assertSame([
      [
        'if' => [
          'properties' => [
            'third_party_settings' => [
              'type' => ['object', 'null'],
              'properties' => ['surface_examples_compliance' => $absent],
            ],
          ],
        ],
        'then' => ['properties' => ['capacity' => $ceiling]],
      ],
    ], $rules, 'The declared type beside each keyword one type reads.');
    $mount = $schema['properties']['third_party_settings']['x-surface'];
    $this->assertTrue($mount['refine']);
    $this->assertSame(['capacity'], $mount['dependsOn']);
    $this->assertTrue($schema['properties']['room']['x-surface']['enumerated']);
    $this->assertCount(3, $this->branches($schema, 'room'));

    // For no values at all, the rule is evaluated, not stated: no license,
    // so a hundred, and the rooms each lower that where they seat fewer.
    [$contract] = $this->staticContract($this->operationSurface('registration.step3'), 'registration.step3');
    $capacity = $contract['schema']['properties']['capacity'];
    $this->assertSame(100, $capacity['maximum']);
    $this->assertSame('Up to 100 without an event license.', $capacity['description']);
    $this->assertArrayNotHasKey('declared', $capacity['x-surface']);
    $this->assertTrue($capacity['x-surface']['enumerated']);
    $ceilings = [];
    foreach ($this->branches($contract['schema'], 'capacity') as [$when, $then]) {
      $ceilings[$when['room']] = $then['maximum'];
    }
    $this->assertSame(['library_reading' => 60, 'library_garden' => 30], $ceilings);
    $this->assertSame([], array_filter($contract['schema']['allOf'], static fn (array $condition): bool => isset($condition['x-surface'])));
  }

  /**
   * Tests variants keep their conditionals, and enumerate inside them.
   */
  public function testVariantsEnumerateInsideTheirConditional(): void {
    [$contract] = $this->staticContract($this->operationSurface('registration.step3'), 'registration.step3');
    $schema = $contract['schema'];
    $this->assertSame(['note'], array_keys($this->variant($schema, 'pricing', 'free', 'ticket')['properties']));
    $this->assertSame(['price', 'currency'], $this->variant($schema, 'pricing', 'paid', 'ticket')['required']);
    $this->assertCount(3, $this->branches($schema, 'room'));
    $this->assertCount(6, $this->branches($schema, 'capacity'));

    // The fruit has no default, so the crate's layers are enumerated in
    // the fruit variant's own schema, under its conditional.
    $this->enableModules(['surface_resource_test']);
    $surface = $this->container->get('surface.resources')->build(CrateStructure::class, new OperationContext('configure'));
    [$contract] = $this->staticContract($surface, 'resource_test.crate');
    $schema = $contract['schema'];
    $this->assertWellFormed($schema);
    $fruit = $this->variant($schema, 'contents', 'fruit', 'contents_settings');
    $this->assertTrue($fruit['properties']['layers']['x-surface']['enumerated']);
    $layers = [];
    foreach ($this->branches($fruit, 'layers') as [$when, $then]) {
      $layers[$when['fruit']] = $then;
    }
    $type = ['integer', 'null'];
    $this->assertSame(['apple' => ['type' => $type, 'maximum' => 4], 'peach' => ['type' => $type, 'maximum' => 2]], $layers);
    $crate = ['contents' => 'fruit', 'contents_settings' => ['fruit' => 'apple', 'layers' => 4]];
    $this->assertTrue($this->validates($schema, $crate));
    $this->assertFalse($this->validates($schema, ['contents_settings' => ['fruit' => 'peach', 'layers' => 4]] + $crate));
    $this->assertTrue($this->validates($schema, ['contents_settings' => ['fruit' => 'peach', 'layers' => 2]] + $crate));
  }

  /**
   * Emits the full schema of one operation, as a client decodes it.
   *
   * @param string $resource
   *   The resource id.
   *
   * @return array
   *   The document, through JSON and back.
   */
  protected function full(string $resource): array {
    $operation = $this->container->get('surface_react.served_operations')->resolve($resource, 'configure', []);
    $served = $this->container->get('surface.contract_emitter')->emitFull(
      $operation->surface,
      $operation->definition->id,
      $operation->operation->id,
      $operation->operation->label,
    );
    return json_decode((string) json_encode($served->document), TRUE);
  }

  /**
   * Tests the full schema of example 2 holds for any values.
   *
   * The second document reads no value. With Riverside's main hall
   * stored it lists every room and a capacity up to a thousand, and
   * states beside them, per venue, its rooms and, per room, its seats; it
   * says nothing that is only true right now; and it is the same
   * document, byte for byte, with the harbour stored. So a validator
   * holding it accepts the library with a library room, which the schema
   * for the stored answers cannot.
   */
  public function testFullSchemaReadsNoValue(): void {
    $document = $this->full('registration.step2');
    $this->assertSame(['resource', 'operation', 'label', 'schema'], array_keys($document), 'No values and no stale beside the schema.');
    $schema = $document['schema'];
    $this->assertWellFormed($schema);
    $this->assertSame(['title', 'venue', 'room'], $schema['required']);

    $room = $schema['properties']['room'];
    $this->assertSame(Venues::rooms(), $this->titles($room));
    $this->assertSame(['dependsOn' => ['venue'], 'enumerated' => TRUE], $room['x-surface']);
    $rooms = [];
    foreach ($this->branches($schema, 'room') as [$when, $then]) {
      $rooms[$when['venue']] = array_keys($this->titles($then));
    }
    $this->assertSame([
      'harbour' => ['harbour_auditorium', 'harbour_deck'],
      'library' => ['library_reading', 'library_garden'],
      'riverside' => ['riverside_main', 'riverside_east'],
    ], $rooms);

    $capacity = $schema['properties']['capacity'];
    $this->assertSame([1, 50], [$capacity['minimum'], $capacity['default']]);
    $this->assertArrayNotHasKey('maximum', $capacity);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertSame(['dependsOn' => ['room'], 'enumerated' => TRUE], $capacity['x-surface']);
    $seats = [];
    foreach ($this->branches($schema, 'capacity') as [$when, $then]) {
      $seats[$when['room']] = $then['maximum'];
    }
    $this->assertSame(array_map(static fn (array $info): int => $info['seats'], Venues::ROOMS), $seats);
    // Nothing that is only true of a moment, anywhere.
    foreach ($this->extensions($schema) as $at => $extension) {
      $this->assertSame([], array_intersect(['refined', 'stale', 'emptyOption', 'chosen', 'widget'], array_keys($extension)), $at);
    }

    // A validator holding it: any venue with one of its own rooms, up to
    // that room's seats, and nothing else.
    $stored = $this->contract('registration.step2', 'configure');
    $this->assertSame(['riverside', 'riverside_main'], [$stored['values']['venue'], $stored['values']['room']]);
    $this->assertTrue($this->validates($schema, $stored['values']));
    $library = ['venue' => 'library', 'room' => 'library_garden', 'capacity' => 30] + $stored['values'];
    $this->assertTrue($this->validates($schema, $library), 'The library with one of its rooms is accepted.');
    $this->assertFalse($this->validates($schema, ['room' => 'riverside_main'] + $library), 'The library with the main hall is refused.');
    foreach (Venues::ROOMS as $name => $info) {
      $values = ['venue' => $info['venue'], 'room' => $name, 'capacity' => $info['seats']] + $stored['values'];
      $this->assertTrue($this->validates($schema, $values), $name . ' at its seats is accepted.');
      $this->assertFalse($this->validates($schema, ['capacity' => $info['seats'] + 1] + $values), $name . ' over its seats is refused.');
    }
    // The schema for the stored answers is for those answers: it offers
    // the riverside's rooms and so refuses the library's.
    $this->assertCount(2, $this->titles($stored['schema']['properties']['room']));
    $this->assertFalse($this->validates($stored['schema'], $library));

    // Stored elsewhere, the full schema is the same document; the other
    // one is not.
    $before = (string) json_encode($this->full('registration.step2'));
    $this->config('surface_examples.registration_step2')
      ->set('venue', 'harbour')
      ->set('room', 'harbour_deck')
      ->set('capacity', 90)
      ->save();
    $moved = $this->contract('registration.step2', 'configure');
    $this->assertSame('harbour_deck', $moved['values']['room']);
    $this->assertNotSame($stored['schema'], $moved['schema']);
    $this->assertSame($before, (string) json_encode($this->full('registration.step2')));
  }

  /**
   * Tests the full schema leaves what cannot be listed as declared.
   *
   * With example 4's module on, the stewards watch the capacity, a number,
   * so their answers cannot be listed: the full schema keeps the declared
   * minimum and marks them dynamic, wider than the pipeline will allow and
   * never narrower. The capacity's license rule is declared, so the full
   * schema states it exactly: 150 people and no license fit neither
   * document. The room beside it is listed, the ticket is its two
   * conditionals with none chosen, and an attached structure is described
   * from nothing, not from its defaults.
   */
  public function testFullSchemaLeavesWhatCannotBeListedAsDeclared(): void {
    $this->enableModules(['surface_examples_compliance']);
    $schema = $this->full('registration.step3')['schema'];
    $this->assertWellFormed($schema);
    $capacity = $schema['properties']['capacity'];
    $this->assertArrayNotHasKey('maximum', $capacity);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertSame([
      'dependsOn' => ['room', 'third_party_settings.surface_examples_compliance.license'],
      'enumerated' => TRUE,
    ], $capacity['x-surface']);
    // The license rule is declared: the frame's rules name its one
    // conditional, which states the capacity.
    $this->assertSame([['capacity']], array_map(static fn (int $index): array => array_keys($schema['allOf'][$index]['then']['properties']), $schema['x-surface']['rules']));
    $this->assertCount(6, $this->branches($schema, 'capacity'));
    $this->assertTrue($schema['properties']['third_party_settings']['x-surface']['refine']);
    $this->assertTrue($schema['properties']['room']['x-surface']['enumerated']);
    $this->assertSame(Venues::rooms(), $this->titles($schema['properties']['room']));
    $this->assertCount(3, $this->branches($schema, 'room'));
    $ticket = $schema['properties']['ticket']['x-surface'];
    $this->assertSame(['pricing', ['free', 'paid']], [$ticket['by'], array_keys($ticket['variants'])]);
    $this->assertArrayNotHasKey('chosen', $ticket);
    $this->assertSame(['price', 'currency'], $this->variant($schema, 'pricing', 'paid', 'ticket')['required']);

    // The other document, for the stored answers: as it stands.
    $stored = $this->contract('registration.step3', 'configure');
    $this->assertSame(100, $stored['schema']['properties']['capacity']['maximum']);
    $this->assertSame('Up to 100 without an event license.', $stored['schema']['properties']['capacity']['description']);
    // Exact where the rule is declared: 150 people and no license fit
    // neither document; with a license, both.
    $crowd = ['capacity' => 150] + $stored['values'];
    $this->assertFalse($this->validates($schema, $crowd));
    $this->assertFalse($this->validates($stored['schema'], $crowd));
    $crowd['third_party_settings']['surface_examples_compliance']['license'] = '2048';
    $crowd['third_party_settings']['surface_examples_compliance']['stewards'] = 3;
    $this->assertTrue($this->validates($schema, $crowd));
    // Wider where it cannot be exact: one steward for 150 people fits the
    // full schema, which cannot list the capacity the stewards watch.
    $crowd['third_party_settings']['surface_examples_compliance']['stewards'] = 1;
    $this->assertTrue($this->validates($schema, $crowd));
    $this->assertFalse($this->validates($schema, ['venue' => 'library'] + $stored['values']), 'What can be listed is still held: the main hall is not the library\'s.');

    // A child whose unit defaults to `cm` is not described for `cm`.
    $this->enableModules(['surface_resource_test']);
    $pantry = $this->container->get('surface.resources')->build(PantryResource::class, PantryResource::create());
    $emitter = $this->container->get('surface.contract_emitter');
    $document = json_decode((string) json_encode($emitter->emitFull($pantry, 'resource_test.pantry')->document['schema']), TRUE);
    $shelf = SchemaResolver::inline($document['properties']['shelf'], $document);
    $this->assertSame(['dependsOn' => ['unit'], 'enumerated' => TRUE], $shelf['properties']['height']['x-surface']);
    $this->assertArrayNotHasKey('maximum', $shelf['properties']['height']);
    $ceilings = [];
    foreach ($this->branches($shelf, 'height') as [$when, $then]) {
      $ceilings[$when['unit']] = $then;
    }
    $type = $shelf['properties']['height']['type'];
    $this->assertSame(['cm' => ['type' => $type, 'maximum' => 200], 'in' => ['type' => $type, 'maximum' => 80]], $ceilings);
    // The schema for its default answers has the default's ceiling: the
    // shelf's def states the conditionals, the shelf here narrows it to
    // what its own values chose.
    $document = json_decode((string) json_encode($emitter->emit($pantry, $pantry->getDefaultValues(), 'resource_test.pantry')->document['schema']), TRUE);
    $this->assertSame('#/$defs/resource_test.pantry.shelf', $document['properties']['shelf']['allOf'][0]['$ref']);
    $this->assertSame(200, $document['properties']['shelf']['allOf'][1]['properties']['height']['maximum']);
    $now = SchemaResolver::inline($document['properties']['shelf'], $document);
    $this->assertSame(200, $now['properties']['height']['maximum']);
    $this->assertTrue($now['properties']['height']['x-surface']['refined']);
  }

  /**
   * Tests example 3's parts are each defined once and referred to.
   *
   * In both documents, the module off and on: the contact and the two
   * tickets are defs under `$defs`, keyed by their structures' ids; the
   * contact's property is a reference beside the label the parent gives
   * it, with nothing of the contact's own shape inlined; each of the
   * ticket's conditionals refers to its variant's def; with the module on,
   * the compliance structure its alter attaches is one more. Nothing in
   * example 3 differs from its def, so nothing is narrowed. A def is the
   * declaration in either document: the same, less what the full schema
   * never says.
   */
  public function testExampleThreeDefinesEachPartOnce(): void {
    foreach ([FALSE, TRUE] as $compliance) {
      if ($compliance) {
        $this->enableModules(['surface_examples_compliance']);
      }
      $documents = [
        'the schema for these answers' => $this->contract('registration.step3', 'configure', widgets: FALSE)['schema'],
        'the full schema' => $this->full('registration.step3')['schema'],
      ];
      foreach ($documents as $which => $schema) {
        $at = ($compliance ? 'With compliance, ' : '') . $which;
        $this->assertWellFormed($schema);
        // With compliance, the structure its alter attaches at its mount
        // is a def too, and so is the license, a shape its key refers to.
        $parts = [
          'registration.step3.ticket.free',
          'registration.step3.ticket.paid',
          'registration.contact',
          ...($compliance ? ['registration.compliance', 'event.license'] : []),
        ];
        $this->assertSame($parts, array_keys($schema['$defs']), $at);

        $contact = $schema['properties']['contact'];
        // Open, watching nothing and with no hint asked for: no
        // `x-surface` at all.
        $this->assertSame(['title', '$ref'], array_keys($contact), $at);
        $this->assertSame(['Contact', '#/$defs/registration.contact'], [$contact['title'], $contact['$ref']], $at);
        // The def is the contact as it declares itself: its keys, and no
        // label, which is the parent's to give; titled by its id, which
        // names the def.
        $def = $schema['$defs']['registration.contact'];
        $this->assertSame(['title', 'type', 'properties', 'additionalProperties', 'required'], array_keys($def), $at);
        $this->assertSame('registration.contact', $def['title'], $at);
        $this->assertSame(['email', 'phone'], array_keys($def['properties']), $at);
        $this->assertSame('idn-email', $def['properties']['email']['format'], $at);

        foreach (['free', 'paid'] as $value) {
          $then = $this->conditional($schema, 'pricing', $value, 'ticket');
          $this->assertSame(['title', '$ref'], array_keys($then), $at);
          $this->assertSame('#/$defs/registration.step3.ticket.' . $value, $then['$ref'], $at);
          $this->assertSame($then['$ref'], $schema['properties']['ticket']['x-surface']['variants'][$value], $at);
        }
        $this->assertSame(['price', 'currency'], $schema['$defs']['registration.step3.ticket.paid']['required'], $at);
        // Nothing narrows, but the compliance structure for these answers,
        // whose stewards follow the capacity its port reads.
        $mount = $schema['properties']['third_party_settings']['properties']['surface_examples_compliance'] ?? [];
        $elsewhere = ['properties' => array_diff_key($schema['properties'], ['third_party_settings' => TRUE])] + $schema;
        $this->assertStringNotContainsString('"allOf":[{"$ref"', (string) json_encode($elsewhere), $at . ': nothing narrows.');
        if ($compliance) {
          $this->assertSame(['capacity' => 'capacity'], $mount['x-surface']['reads'], $at);
          if ($which === 'the full schema') {
            $this->assertSame('#/$defs/registration.compliance', $mount['$ref'], $at);
          }
          else {
            $stewards = [
              'type' => 'integer',
              'description' => 'At least 1 steward for 50 attendees.',
              'minimum' => 1,
            ];
            $this->assertSame([
              ['$ref' => '#/$defs/registration.compliance'],
              ['type' => 'object', 'properties' => ['stewards' => $stewards]],
            ], $mount['allOf'], $at);
            $this->assertSame(['dependsOn' => ['capacity'], 'refined' => TRUE], $mount['x-surface']['within']['/allOf/1/properties/stewards'], $at);
          }
        }
      }
      $these = $documents['the schema for these answers']['$defs'];
      foreach ($documents['the full schema']['$defs'] as $key => $def) {
        $this->assertSame($def, $this->withoutPresent($these[$key]), $key);
      }
    }
  }

  /**
   * Strips what only one moment says from every `x-surface` in a schema.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The schema, as the full schema would say it.
   */
  protected function withoutPresent(array $schema): array {
    foreach ($schema as $keyword => $value) {
      if ($keyword === 'x-surface' && is_array($value)) {
        $schema[$keyword] = array_diff_key($value, array_flip(['refined', 'stale', 'emptyOption', 'chosen']));
        // An `x-surface` left with nothing to say is not written.
        if ($schema[$keyword] === []) {
          unset($schema[$keyword]);
        }
      }
      elseif (is_array($value)) {
        $schema[$keyword] = $this->withoutPresent($value);
      }
    }
    return $schema;
  }

  /**
   * Tests example 3's full schema is the same document whatever is stored.
   */
  public function testExampleThreeFullSchemaReadsNoValue(): void {
    $before = (string) json_encode($this->full('registration.step3'));
    $stored = $this->contract('registration.step3', 'configure');
    $this->config('surface_examples.registration_step3')
      ->set('venue', 'harbour')
      ->set('room', 'harbour_deck')
      ->set('capacity', 90)
      ->set('pricing', 'paid')
      ->set('ticket', ['price' => 9.5, 'currency' => 'GBP'])
      ->set('contact', ['email' => 'desk@example.com', 'phone' => '555 0100'])
      ->save();
    $moved = $this->contract('registration.step3', 'configure');
    $this->assertNotSame($stored['schema'], $moved['schema']);
    $this->assertSame($before, (string) json_encode($this->full('registration.step3')));
  }

  /**
   * Tests a room closed at runtime: never stated, always refused.
   *
   * Example 4's alter takes away the rooms a site has closed, which only
   * the site knows when asked, so its refiner is a runtime one. Nothing
   * closed, nothing changes: the schema for these answers is the same
   * document as with a closed room no venue here has. The room is
   * `enumerated` for the owner's refiner and `runtime` for the alter's in
   * both documents. With the East room closed, the full schema is the
   * same document byte for byte and still lists it under Riverside, while
   * the schema for these answers leaves it out and says why. The model
   * case of the invariant: a validator holding the full schema accepts
   * the East room, wider than the runtime by design, and the pipeline,
   * whose refinement has the last word, refuses it.
   */
  public function testClosedRoomIsLeftToTheRuntime(): void {
    $this->enableModules(['surface_examples_compliance']);
    $state = $this->container->get('state');
    $closed = 'surface_examples_compliance.closed_rooms';
    $this->assertNull($state->get($closed));
    $full = (string) json_encode($this->full('registration.step3'));
    $stored = $this->contract('registration.step3', 'configure', widgets: FALSE);
    $state->set($closed, ['harbour_deck']);
    $this->assertSame((string) json_encode($stored), (string) json_encode($this->contract('registration.step3', 'configure', widgets: FALSE)), 'A closed room elsewhere changes nothing here.');

    $state->set($closed, ['riverside_east']);
    $this->assertSame($full, (string) json_encode($this->full('registration.step3')), 'The full schema reads no State.');
    $schema = $this->full('registration.step3')['schema'];
    $room = $schema['properties']['room'];
    $this->assertSame(['dependsOn' => ['venue'], 'enumerated' => TRUE, 'live' => TRUE], $room['x-surface']);
    $this->assertArrayHasKey('riverside_east', $this->titles($room));
    $riverside = [];
    foreach ($this->branches($schema, 'room') as [$when, $then]) {
      $riverside[$when['venue']] = array_keys($this->titles($then));
    }
    $this->assertSame(['riverside_main', 'riverside_east'], $riverside['riverside']);

    $now = $this->contract('registration.step3', 'configure', widgets: FALSE);
    $room = $now['schema']['properties']['room'];
    $this->assertSame(['riverside_main' => 'Main hall'], $this->titles($room));
    $this->assertSame('The East room is closed.', $room['description']);
    $this->assertTrue($room['x-surface']['refined']);
    $this->assertTrue($room['x-surface']['live']);
    $this->assertWellFormed($now['schema']);
    $this->assertTrue($this->validates($now['schema'], $now['values']));

    // Wider than the runtime, by design, and the runtime has the last
    // word.
    $east = ['room' => 'riverside_east', 'capacity' => 100] + $now['values'];
    $this->assertTrue($this->validates($schema, $east), 'The full schema accepts the East room.');
    $this->assertFalse($this->validates($now['schema'], $east), 'The schema for these answers does not.');
    $surface = $this->operationSurface('registration.step3');
    $pipeline = $this->container->get('surface.pipeline');
    $this->assertNotContains('room', $pipeline->validate($surface, $now['values'])->keys());
    $this->assertContains('room', $pipeline->validate($surface, $east)->keys(), 'The pipeline refuses it.');
    $state->delete($closed);
    $this->assertNotContains('room', $pipeline->validate($surface, $east)->keys(), 'Opened again, it is accepted.');
  }

  /**
   * Tests one child class is one def, and a site narrows only what differs.
   *
   * The cupboard attaches the shelf twice and the bin twice, and its
   * context knows the left bin. Each class is one def; the two shelves and
   * the right bin refer to theirs and say nothing more in the full
   * schema; the left bin's site composes its def with the lock its
   * context put on it. The shelf's def carries the shelf's own
   * conditionals and its alter. For these answers, each shelf's unit
   * chose a ceiling, which the site narrows the def to. A validator
   * holding a value to a def and its narrowing gives the verdict it gives
   * holding it to the child's schema written out inline.
   */
  public function testOneDefPerChildClass(): void {
    $this->enableModules(['surface_resource_test']);
    $context = (new OperationContext('update'))->withChild('left', new OperationContext('update', known: ['bin' => 'left']));
    $cupboard = $this->container->get('surface.resources')->build(CupboardStructure::class, $context);
    $emitter = $this->container->get('surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($cupboard, 'resource_test.cupboard')->document['schema']), TRUE);
    $this->assertWellFormed($full);
    $this->assertSame(['resource_test.pantry.shelf', 'resource_test.cupboard.bin'], array_keys($full['$defs']));
    $shelf = '#/$defs/resource_test.pantry.shelf';
    $bin = '#/$defs/resource_test.cupboard.bin';
    $this->assertSame(['title' => 'Upper shelf', '$ref' => $shelf], array_diff_key($full['properties']['upper'], ['x-surface' => TRUE]));
    $this->assertSame(['title' => 'Lower shelf', '$ref' => $shelf], array_diff_key($full['properties']['lower'], ['x-surface' => TRUE]));
    $this->assertSame(['title' => 'Right bin', '$ref' => $bin], array_diff_key($full['properties']['right'], ['x-surface' => TRUE]));

    // The shelf's own conditionals, its alter's key and its alter's words.
    $def = $full['$defs']['resource_test.pantry.shelf'];
    $ceilings = [];
    foreach ($this->branches($def, 'height') as [$when, $then]) {
      $ceilings[$when['unit']] = $then;
    }
    $type = $def['properties']['height']['type'];
    $this->assertSame(['cm' => ['type' => $type, 'maximum' => 200], 'in' => ['type' => $type, 'maximum' => 80]], $ceilings, 'The declared type beside the bound.');
    $this->assertSame('Measured inside.', $def['properties']['height']['description']);
    $this->assertArrayHasKey('sturdy', $def['properties']['third_party_settings']['properties']['surface_resource_test']['properties']);

    // The bin as declared, which no context has locked; the left bin's
    // lock is its site's.
    $declared = $full['$defs']['resource_test.cupboard.bin']['properties']['bin'];
    $this->assertSame([], array_intersect_key($declared, ['readOnly' => TRUE, 'const' => TRUE, 'default' => TRUE]));
    $this->assertSame([
      ['$ref' => $bin],
      [
        'type' => 'object',
        'properties' => [
          'bin' => [
            'default' => 'left',
            'readOnly' => TRUE,
            'const' => 'left',
          ],
        ],
      ],
    ], $full['properties']['left']['allOf']);
    // The lock is the narrowing's `readOnly` and `const`; the key's
    // x-surface would say nothing, so the site carries no `within` entry
    // for it.
    $this->assertArrayNotHasKey('within', $full['properties']['left']['x-surface'] ?? []);
    $this->assertArrayNotHasKey('$ref', $full['properties']['left']);
    $this->assertSame('Left bin', $full['properties']['left']['title']);

    // For these answers: each shelf narrowed to its unit's ceiling, still
    // one def.
    $values = $cupboard->getDefaultValues();
    $values['right']['bin'] = 'right';
    $these = json_decode((string) json_encode($emitter->emit($cupboard, $values, 'resource_test.cupboard')->document['schema']), TRUE);
    $this->assertWellFormed($these);
    $this->assertSame(['resource_test.pantry.shelf', 'resource_test.cupboard.bin'], array_keys($these['$defs']));
    foreach (['upper', 'lower'] as $key) {
      [$reference, $narrowing] = $these['properties'][$key]['allOf'];
      $this->assertSame(['$ref' => $shelf], $reference);
      $this->assertSame(['height'], array_keys($narrowing['properties']));
      $this->assertSame(200, $narrowing['properties']['height']['maximum']);
      $this->assertTrue($these['properties'][$key]['x-surface']['within']['/allOf/1/properties/height']['refined']);
      $this->assertTrue($these['properties'][$key]['x-surface']['refined']);
    }
    $this->assertSame($bin, $these['properties']['right']['$ref']);

    $cases = [
      [[], TRUE],
      [['upper' => ['height' => 200] + $values['upper']], TRUE],
      [['upper' => ['height' => 201] + $values['upper']], FALSE],
      [['lower' => ['unit' => 'in', 'height' => 81] + $values['lower']], FALSE],
      [['lower' => ['unit' => 'in', 'height' => 80] + $values['lower']], TRUE],
      [['left' => ['bin' => 'elsewhere', 'size' => 5]], FALSE],
      [['left' => ['bin' => 'left', 'size' => 11]], FALSE],
      [['right' => ['bin' => 'anything', 'size' => 1]], TRUE],
      [['right' => ['bin' => NULL, 'size' => 1]], FALSE],
      [['right' => ['bin' => 'right', 'size' => 1, 'lid' => TRUE]], FALSE],
    ];
    foreach ($cases as $i => [$over, $verdict]) {
      $answers = array_replace($values, $over);
      $this->assertSame($verdict, $this->validates($full, $answers), 'The full schema, case ' . $i);
      $this->assertSame($verdict, $this->validates(SchemaResolver::inline($full), $answers), 'The full schema inline, case ' . $i);
      // The schema for the answers it was emitted for: the shelf's unit as
      // given, the ceiling it chose with it.
      $emitted = json_decode((string) json_encode($emitter->emit($cupboard, $answers, 'resource_test.cupboard')->document['schema']), TRUE);
      $this->assertSame($verdict, $this->validates($emitted, $answers), 'The schema for these answers, case ' . $i);
      $this->assertSame($verdict, $this->validates(SchemaResolver::inline($emitted), $answers), 'The schema for these answers inline, case ' . $i);
      if ($i !== 5) {
        // A value sent for a locked key is not refused by the pipeline but
        // replaced by what the context knows; the schema says the one
        // value it will hold.
        $this->assertSame($verdict, $this->accepts($cupboard, $answers), 'The pipeline, case ' . $i);
      }
    }
  }

  /**
   * Tests two children claiming one id with two declarations keep both.
   *
   * The build step names a child by its class, so one id is one
   * declaration there; only a hand-sealed data surface can give one id to
   * two shapes, here one inside the other. A site may only narrow its
   * def, and neither shape narrows the other, so the second is defined
   * beside the first, and emission ends, as it would for any finite data
   * surface.
   */
  public function testOneIdTwoDeclarationsKeepsBoth(): void {
    $leaf = new SurfaceBuilder();
    $leaf->setDefinition('depth', DataDefinition::create('integer'));
    $node = new SurfaceBuilder();
    $node->setDefinition('name', DataDefinition::create('string'));
    $node->attach('next', new Attachment($leaf->seal(), NULL, 'loop.node'));
    $root = new SurfaceBuilder();
    $root->attach('first', new Attachment($node->seal(), NULL, 'loop.node'));
    $surface = $root->seal();
    $emitter = $this->container->get('surface.contract_emitter');
    foreach ([
      $emitter->emitFull($surface, 'loop')->document['schema'],
      $emitter->emit($surface, $surface->getDefaultValues(), 'loop')->document['schema'],
    ] as $schema) {
      $schema = json_decode((string) json_encode($schema), TRUE);
      $this->assertWellFormed($schema);
      // A def is filed when it is finished, so the inner one, finished
      // first, keeps the id.
      $this->assertSame(['loop.node', 'loop.node@2'], array_keys($schema['$defs']));
      $this->assertSame(['depth'], array_keys($schema['$defs']['loop.node']['properties']));
      $this->assertSame('#/$defs/loop.node', $schema['$defs']['loop.node@2']['properties']['next']['$ref']);
      $this->assertSame('#/$defs/loop.node@2', $schema['properties']['first']['$ref']);
      $this->assertTrue($this->validates($schema, ['first' => ['name' => 'a', 'next' => ['depth' => 1]]]));
      $this->assertFalse($this->validates($schema, ['first' => ['name' => 'a', 'next' => ['name' => 'b']]]));
      $inline = SchemaResolver::inline($schema);
      $this->assertArrayNotHasKey('$defs', $inline);
      $this->assertSame(['depth'], array_keys($inline['properties']['first']['properties']['next']['properties']));
    }
  }

  /**
   * Tests the content type resource: locked on edit, open on add.
   */
  public function testNodeType(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $edit = $this->contract('node.type', 'update', ['type' => 'article']);
    $schema = $edit['schema'];
    $type = $schema['properties']['type'];
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

    $add = $this->contract('node.type', 'create');
    $type = $add['schema']['properties']['type'];
    $this->assertArrayNotHasKey('readOnly', $type);
    $this->assertArrayNotHasKey('const', $type);
    // Named with what it checks, the reason the build gives it; computed
    // from the name unless sent, and offered.
    $this->assertContains([
      'constraint' => 'SurfaceUnique',
      'message' => 'Refused when a content type named this already exists.',
    ], $type['x-surface']['checkedOnServer']);
    $this->assertSame(['from' => ['name'], 'when' => 'unless_sent'], $type['x-surface']['computed']);
    $this->assertSame(['^[a-z0-9_]+$', 32], [$type['pattern'], $type['maxLength']]);
  }

  /**
   * Tests the demo block's structure, built as its host builds it.
   */
  public function testDemoBlock(): void {
    $surface = $this->container->get('surface.resources')->build(DemoBlockStructure::class, new OperationContext('configure'));
    $document = $this->container->get('surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.surface_examples_plugins', widgets: TRUE)
      ->document;
    $contract = json_decode((string) json_encode($document), TRUE);
    $this->assertNull($contract['operation']);
    $schema = $contract['schema'];
    $headline = $schema['properties']['headline'];
    $this->assertSame(50, $headline['maxLength']);
    $this->assertSame(['Quarterly report'], $headline['examples']);
    $this->assertArrayHasKey('user', $this->titles($schema['properties']['entity_type']));
    $this->assertSame(['entity_type'], $schema['properties']['bundle']['x-surface']['dependsOn']);
    $this->assertSame(['entity_type', 'bundle'], $schema['properties']['field']['x-surface']['dependsOn']);
    $this->assertSame(['integer'], (array) $schema['properties']['limit']['type']);
    $variants = $schema['properties']['presentation_settings'];
    $this->assertSame('variants', $variants['x-surface']['widget']);
    $this->assertSame('list', $variants['x-surface']['chosen']);
    $grid = $this->variant($schema, 'presentation', 'grid', 'presentation_settings');
    $this->assertSame(1, $grid['properties']['columns']['minimum']);
    $this->assertSame(6, $grid['properties']['columns']['maximum']);
    $this->assertSame(['show_summary' => TRUE], $contract['values']['presentation_settings']);
  }

  /**
   * An example 3 event, every key answered, as the shipped settings hold it.
   */
  protected const EVENT = [
    'title' => 'Meetup',
    'open' => TRUE,
    'venue' => 'riverside',
    'room' => 'riverside_main',
    'capacity' => 50,
    'pricing' => 'free',
    'ticket' => ['note' => ''],
    'contact' => ['email' => 'events@example.com', 'phone' => ''],
  ];

  /**
   * Value sets for example 3, each with its verdict in three places.
   *
   * The same with example 4's module on: the capacity's license rule is
   * declared, so the full schema still states every ceiling.
   *
   * @return array<string, array{0: array, 1: bool, 2: bool, 3: bool}>
   *   The values over EVENT, then whether the pipeline accepts them,
   *   whether the full schema does, and whether the schema emitted for
   *   those values does.
   */
  protected static function verdicts(): array {
    $paid = ['pricing' => 'paid', 'ticket' => ['price' => 12.5, 'currency' => 'EUR']];
    $garden = ['venue' => 'library', 'room' => 'library_garden'];
    return [
      'as shipped' => [[], TRUE, TRUE, TRUE],
      'a paid ticket' => [$paid, TRUE, TRUE, TRUE],
      'paid, the free ticket' => [['pricing' => 'paid'], FALSE, FALSE, FALSE],
      'paid, nothing to pay' => [['ticket' => ['price' => 0, 'currency' => 'EUR']] + $paid, FALSE, FALSE, FALSE],
      'paid, a currency not offered' => [['ticket' => ['price' => 5, 'currency' => 'JPY']] + $paid, FALSE, FALSE, FALSE],
      'free, the paid ticket' => [['ticket' => ['price' => 5, 'currency' => 'EUR']], FALSE, FALSE, FALSE],
      // `format` is advisory: the pipeline's Email check refuses it.
      'a contact address that is none' => [['contact' => ['email' => 'nobody', 'phone' => '']], FALSE, TRUE, TRUE],
      'a contact with no address' => [['contact' => ['email' => NULL, 'phone' => '555']], FALSE, FALSE, FALSE],
      'a contact key nobody declares' => [['contact' => ['fax' => '1'] + self::EVENT['contact']], FALSE, FALSE, FALSE],
      'another venue\'s room' => [['room' => 'library_reading'], FALSE, FALSE, FALSE],
      'more than the main hall seats' => [['capacity' => 401], FALSE, FALSE, FALSE],
      'the library\'s garden room' => [['capacity' => 30] + $garden, TRUE, TRUE, TRUE],
      'more than the garden room seats' => [['capacity' => 31] + $garden, FALSE, FALSE, FALSE],
    ];
  }

  /**
   * Answers whether the pipeline accepts and validates a value set.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param array $values
   *   The values, every key answered.
   *
   * @return bool
   *   Whether accept() takes them and validate() refuses nothing.
   */
  protected function accepts(SurfaceInterface $surface, array $values): bool {
    try {
      $accepted = $this->pipeline()->accept($surface, $values);
    }
    catch (UnknownKeysException | TypeMismatchException | VariantMismatchException) {
      return FALSE;
    }
    return iterator_count($this->pipeline()->validate($surface, $accepted)) === 0;
  }

  /**
   * Tests each document refuses and accepts what it did, as the pipeline.
   *
   * The verdicts are stated, not read off an older document, so the shape
   * the schemas are written in may change and these may not: the full
   * schema and the schema for each value set hold example 3 to what the
   * pipeline holds it to, its attached structures included, with the
   * compliance module off and on. On, the license's rule is declared, so
   * the full schema holds a crowd with no license to it as well.
   */
  public function testDocumentsKeepTheirVerdicts(): void {
    foreach ([FALSE, TRUE] as $compliance) {
      $cases = static::verdicts();
      $event = static::EVENT;
      if ($compliance) {
        $this->enableModules(['surface_examples_compliance']);
        $mount = static fn (?string $license, int $stewards): array => [
          'third_party_settings' => [
            'surface_examples_compliance' => ['license' => $license, 'stewards' => $stewards],
          ],
        ];
        $event += $mount(NULL, 1);
        $licensed = $mount('2048', 3);
        $cases += [
          'a crowd with no license' => [['capacity' => 150], FALSE, FALSE, FALSE],
          'a crowd with a license' => [['capacity' => 150] + $licensed, TRUE, TRUE, TRUE],
          'a license that is not four digits' => [$mount('EV-1', 1), FALSE, FALSE, FALSE],
        ];
      }
      $emitter = $this->container->get('surface.contract_emitter');
      $resources = $this->container->get('surface.resources');
      $surface = $resources->build('registration.step3', $resources->operation('registration.step3', 'configure'));
      $full = json_decode((string) json_encode($emitter->emitFull($surface, 'registration.step3', 'configure')->document['schema']), TRUE);
      $this->assertWellFormed($full);
      foreach ($cases as $case => [$over, $pipeline, $any, $these]) {
        $values = array_replace($event, $over);
        $label = ($compliance ? 'With compliance, ' : '') . $case;
        $this->assertSame($pipeline, $this->accepts($surface, $values), $label . ': the pipeline.');
        $this->assertSame($any, $this->validates($full, $values), $label . ': the full schema.');
        $emitted = json_decode((string) json_encode($emitter->emit($surface, $values, 'registration.step3', 'configure')->document['schema']), TRUE);
        $this->assertSame($these, $this->validates($emitted, $values), $label . ': the schema for these answers.');
      }
    }
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
    $edit = $this->contract('node.type', 'update', ['type' => 'article']);
    $this->assertWellFormed($edit['schema']);
    $this->assertTrue($this->validates($edit['schema'], $edit['values']));
    $this->assertFalse($this->validates($edit['schema'], ['type' => 'page'] + $edit['values']), 'The locked machine name holds one value.');
    $this->assertFalse($this->validates($edit['schema'], ['name' => str_repeat('a', 256)] + $edit['values']));
    $this->assertWellFormed($this->contract('node.type', 'create')['schema']);

    $surface = $this->container->get('surface.resources')->build(DemoBlockStructure::class, new OperationContext('configure'));
    $document = $this->container->get('surface.contract_emitter')
      ->emit($surface, $surface->getDefaultValues(), 'block.surface_examples_plugins', widgets: TRUE)
      ->document;
    $contract = json_decode((string) json_encode($document), TRUE);
    $this->assertWellFormed($contract['schema']);
    $this->assertTrue($this->validates($contract['schema'], $contract['values']));
    $this->assertFalse($this->validates($contract['schema'], ['headline' => str_repeat('a', 51)] + $contract['values']));
  }

}
