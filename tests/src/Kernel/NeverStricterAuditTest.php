<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Pipeline\ShapeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\VariantMismatchException;
use Drupal\data_surface\Storage\StateStorage;
use Drupal\data_surface_examples_compliance\Alter\RegistrationComplianceAlter;
use Drupal\data_surface_surface_test\Resource\Cellar\CellarResource;
use Drupal\data_surface_surface_test\Storage\CellarStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Exceptions\InvalidKeywordException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the audit of "never stricter than the runtime", case by case.
 *
 * Each test is one section of docs/audit-never-stricter.md and asserts the
 * behavior as it is today: what the pipeline (accept, refine, validate)
 * does with a value set, what it stores, and what opis/json-schema says
 * about the same value set under the full schema (emitFull()) and the
 * schema for these answers (emit()). A verdict that breaks the invariant
 * is marked where it is asserted, with a @todo naming the audit section,
 * so changing it later has to change a test on purpose.
 *
 * Two readings of the invariant run through it. On the raw input a caller
 * sends, the schema is stricter wherever accept() normalizes a notation
 * (a text number, an empty string, an absent key). On the document
 * accept() makes of it, the schema holds in those places; where it fails
 * even there, the audit says so.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class NeverStricterAuditTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * Example 3's values as shipped, every key answered.
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
   * The cellar as stored for the collection cases.
   */
  protected const CELLAR = [
    'larder' => 'main',
    'size' => 'large',
    'racks' => [
      ['shelf' => 'low', 'spices' => 7, 'label' => 'Everyday'],
      ['shelf' => 'top', 'spices' => 2, 'label' => 'Spare'],
    ],
    'shelves' => [],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_react',
    'data_surface_surface_test',
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
   * Encodes values the way a JSON client sends them.
   *
   * An empty PHP array at the top is an object, as every value set is.
   *
   * @param mixed $values
   *   The values.
   *
   * @return mixed
   *   The decoded JSON.
   */
  protected function json(mixed $values): mixed {
    $encoded = $values === [] ? '{}' : (string) json_encode($values, JSON_PRESERVE_ZERO_FRACTION);
    return json_decode($encoded);
  }

  /**
   * Answers whether opis/json-schema accepts values under a schema.
   *
   * @param array $schema
   *   The schema, as emitted.
   * @param mixed $values
   *   The values.
   *
   * @return bool
   *   Whether they validate.
   */
  protected function validates(array $schema, mixed $values): bool {
    $validator = new Validator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    return $validator->validate($this->json($values), (string) json_encode($schema))->isValid();
  }

  /**
   * Answers whether opis/json-schema's compliant validator accepts values.
   *
   * The suite's opis validator runs with its extensions on, one of which
   * (allowDefaults) drops a property with a `default` from `required` and
   * fills it in. The compliant validator reads the schema as the standard
   * does, as a client's validator would.
   *
   * @param array $schema
   *   The schema, as emitted.
   * @param mixed $values
   *   The values.
   *
   * @return bool
   *   Whether they validate.
   */
  protected function validatesStrictly(array $schema, mixed $values): bool {
    $validator = new CompliantValidator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    return $validator->validate($this->json($values), (string) json_encode($schema))->isValid();
  }

  /**
   * Emits the full schema of a data surface, as a client decodes it.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param string $id
   *   Its id.
   *
   * @return array
   *   The schema.
   */
  protected function full(DataSurfaceInterface $surface, string $id = 'audit'): array {
    $document = $this->container->get('data_surface.contract_emitter')->emitFull($surface, $id)->document;
    return json_decode((string) json_encode($document['schema']), TRUE);
  }

  /**
   * Emits the document for one set of values, as a client decodes it.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values it is emitted for.
   * @param string $id
   *   Its id.
   *
   * @return array
   *   The document: `schema`, `values`, `stale`.
   */
  protected function these(DataSurfaceInterface $surface, array $values, string $id = 'audit'): array {
    $document = $this->container->get('data_surface.contract_emitter')->emit($surface, $values, $id)->document;
    return json_decode((string) json_encode($document), TRUE);
  }

  /**
   * Runs accept() and validate() over one input, as submit() does.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $input
   *   The raw input.
   * @param array $current
   *   What storage holds.
   *
   * @return array{0: bool, 1: array|null, 2: string[]}
   *   Whether nothing blocking was refused, the accepted values (NULL when
   *   accept() threw), and the paths of the stale entries.
   */
  protected function verdict(DataSurfaceInterface $surface, array $input, array $current = []): array {
    try {
      $accepted = $this->pipeline()->accept($surface, $input, $current);
    }
    catch (UnknownKeysException | ShapeMismatchException | VariantMismatchException) {
      return [FALSE, NULL, []];
    }
    $violations = $this->pipeline()->validate($surface, $accepted, $current);
    $stale = array_map(static fn ($violation): string => $violation->fullPath(), $violations->stale());
    return [$violations->isEmpty(), $accepted, $stale];
  }

  /**
   * Builds a resource in its operation.
   *
   * @param string $resource
   *   The resource id or class.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The operation's arguments.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function build(string $resource, string $operation = 'configure', array $arguments = []): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->buildOperation($resource, $operation, $arguments);
  }

  /**
   * Submits values through a resource's own storage.
   *
   * @param string $resource
   *   The resource id.
   * @param array $input
   *   The raw input.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(string $resource, array $input): DataSurfaceResult {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation($resource, 'configure');
    $surface = $resources->build($resource, $context);
    return $this->pipeline()->submit($surface, $input, $resources->storage($resource, $context, $surface));
  }

  /**
   * Reads what a resource's storage holds.
   *
   * @param string $resource
   *   The resource id.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $resource): array {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation($resource, 'configure');
    $surface = $resources->build($resource, $context);
    return $resources->storage($resource, $context, $surface)->load($surface);
  }

  /**
   * Seals a data surface from definitions, for the generic cases.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $definitions
   *   The definitions, by key.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The data surface.
   */
  protected function surface(array $definitions): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder();
    foreach ($definitions as $name => $definition) {
      $builder->setDefinition($name, $definition);
    }
    return $builder->seal();
  }

  /**
   * Enables example 4's alter, the licence, the stewards and closed rooms.
   */
  protected function enableCompliance(): void {
    $this->enableModules(['data_surface_examples_compliance']);
  }

  /**
   * Example 3's values with example 4's mount answered.
   *
   * @param string|null $licence
   *   The licence.
   * @param int $stewards
   *   The stewards.
   *
   * @return array
   *   The values.
   */
  protected static function mounted(?string $licence, int $stewards): array {
    return [
      'third_party_settings' => [
        'data_surface_examples_compliance' => ['licence' => $licence, 'stewards' => $stewards],
      ],
    ];
  }

  /**
   * Acts as a larder keeper who may stock the racks, as CollectionTest does.
   *
   * @param string ...$permissions
   *   More permissions.
   */
  protected function actAsLarderKeeper(string ...$permissions): void {
    $this->setUpCurrentUser([], ['keep a larder', 'stock spice rack', ...$permissions]);
    $this->container->get('state')->set(CellarStorage::key('main'), self::CELLAR);
  }

  /**
   * Case 1: the casting table accepts notations the schema does not.
   *
   * The form posts every value as text, and accept() casts it through
   * the casting table; the schema states the canonical JSON type only. So
   * on the raw input both documents are stricter than the pipeline for a
   * form client, and for a JSON client that sends a number as a string. On
   * the value accept() makes of it, both hold.
   */
  public function testCastingTableAcceptsNotationsTheSchemaRefuses(): void {
    $surface = $this->surface([
      'count' => DataDefinition::create('integer')->setLabel('Count')
        ->addConstraint('Range', ['min' => 1, 'max' => 1000]),
      'ratio' => DataDefinition::create('float')->setLabel('Ratio'),
      'flag' => DataDefinition::create('boolean')->setLabel('Flag'),
      'name' => DataDefinition::create('string')->setLabel('Name'),
    ]);
    $full = $this->full($surface);
    $cases = [
      ['count', '50', 50],
      ['count', ' 7 ', 7],
      ['count', '+7', 7],
      ['count', '007', 7],
      ['count', '2.0', 2],
      ['count', '1e3', 1000],
      ['ratio', '12.5', 12.5],
      ['ratio', '2', 2.0],
      ['ratio', ' 1.5 ', 1.5],
      ['ratio', '1e3', 1000.0],
      ['flag', '1', TRUE],
      ['flag', 'true', TRUE],
      ['flag', 'on', TRUE],
      ['flag', 'yes', TRUE],
      ['flag', '0', FALSE],
      ['flag', 'false', FALSE],
      ['flag', 'off', FALSE],
      ['flag', 'no', FALSE],
      ['flag', ' OFF ', FALSE],
      ['flag', 1, TRUE],
      ['flag', 0, FALSE],
      ['name', 7, '7'],
      ['name', 1.5, '1.5'],
      ['name', TRUE, '1'],
    ];
    foreach ($cases as [$key, $raw, $canonical]) {
      $label = sprintf('%s = %s', $key, var_export($raw, TRUE));
      [$valid, $accepted] = $this->verdict($surface, [$key => $raw]);
      $this->assertTrue($valid, $label . ': the pipeline accepts it.');
      $this->assertSame($canonical, $accepted[$key], $label . ': and stores the cast value.');
      // @todo Audit section 1: both documents refuse the notation the
      //   pipeline accepts. The recommendation is to scope the invariant
      //   to the document accept() makes, not to widen the type.
      $this->assertFalse($this->validates($full, [$key => $raw]), $label . ': the full schema refuses the raw input.');
      $this->assertFalse($this->validates($this->these($surface, [$key => $raw])['schema'], [$key => $raw]), $label . ': so does the schema for these answers.');
      // Holds on the accepted value.
      $this->assertTrue($this->validates($full, $accepted), $label . ': the full schema accepts what accept() made of it.');
    }

    // A whole float is an integer in JSON Schema too: holds.
    [$valid, $accepted] = $this->verdict($surface, ['count' => 2.0]);
    $this->assertTrue($valid);
    $this->assertSame(2, $accepted['count']);
    $this->assertTrue($this->validates($full, ['count' => 2.0]));

    // A fraction is refused by both.
    $this->assertFalse($this->verdict($surface, ['count' => '1.9'])[0]);
    $this->assertFalse($this->validates($full, ['count' => '1.9']));

    // The shipped form posts example 2's capacity and checkbox as text.
    $step2 = $this->build('registration.step2');
    $posted = [
      'title' => 'Spring meetup',
      'open' => '1',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'capacity' => '120',
    ];
    [$valid, $accepted] = $this->verdict($step2, $posted, $this->stored('registration.step2'));
    $this->assertTrue($valid);
    $this->assertSame(120, $accepted['capacity']);
    $this->assertTrue($accepted['open']);
    // @todo Audit section 1.
    $this->assertFalse($this->validates($this->full($step2, 'registration.step2'), $posted));
    $this->assertTrue($this->validates($this->full($step2, 'registration.step2'), $accepted));
  }

  /**
   * Beside case 1: values accept() leaves in no type the key declares.
   *
   * Not the invariant, an outright finding: core's PrimitiveType check
   * compares loosely, so a value the casting table leaves "unchanged —
   * the constraint refuses it" passes, and the pipeline stores a value of
   * another type. Neither document accepts the stored value. Reported
   * separately in the audit ("Outside the ten cases"), and left.
   */
  public function testValuesTheCastLeavesInAnotherTypeAreStored(): void {
    $surface = $this->surface([
      'count' => DataDefinition::create('integer')->setLabel('Count'),
      'ratio' => DataDefinition::create('float')->setLabel('Ratio'),
      'flag' => DataDefinition::create('boolean')->setLabel('Flag'),
    ]);
    $full = $this->full($surface);
    foreach ([
      ['count', TRUE],
      ['ratio', TRUE],
      ['flag', 1.0],
      ['flag', '01'],
      ['flag', '1.0'],
    ] as [$key, $raw]) {
      $label = sprintf('%s = %s', $key, var_export($raw, TRUE));
      [$valid, $accepted] = $this->verdict($surface, [$key => $raw]);
      $this->assertTrue($valid, $label . ': the pipeline accepts it.');
      $this->assertSame($raw, $accepted[$key], $label . ': and keeps it as it arrived.');
      $this->assertFalse($this->validates($full, $accepted), $label . ': no document accepts what is stored.');
    }
    // With a Range beside it, the integer TRUE is refused after all.
    $ranged = $this->surface(['count' => DataDefinition::create('integer')->setLabel('Count')->addConstraint('Range', ['min' => 0])]);
    $this->assertFalse($this->verdict($ranged, ['count' => TRUE])[0]);
  }

  /**
   * Case 2: an undeclared key is refused in every position but one.
   *
   * At the top, inside an attached structure, inside a slot's variant,
   * under third_party_settings for a module that mounts nothing, and
   * inside a collection item, accept() throws and the run is refused;
   * both documents refuse it too (`additionalProperties: false`). The
   * exception is a collection item whose every key holds nothing: it is
   * dropped as empty before its keys are read, unknown ones included.
   */
  public function testUnknownKeysAreRefused(): void {
    $step3 = $this->build('registration.step3');
    $full = $this->full($step3, 'registration.step3');
    $unknown = UnknownKeysException::class;
    $cases = [
      'at the top' => [self::EVENT + ['fax' => '1'], '', $unknown],
      'inside an attached structure' => [
        ['contact' => ['fax' => '1'] + self::EVENT['contact']] + self::EVENT,
        'contact',
        $unknown,
      ],
      'inside a slot\'s variant' => [['ticket' => ['note' => '', 'fax' => '1']] + self::EVENT, 'ticket', $unknown],
      'another variant\'s key in a slot' => [
        ['ticket' => ['price' => 5]] + self::EVENT,
        'ticket',
        VariantMismatchException::class,
      ],
      'third_party_settings, no module mounting' => [
        self::EVENT + ['third_party_settings' => ['other' => ['a' => 1]]],
        '',
        $unknown,
      ],
    ];
    foreach ($cases as $case => [$input, $path, $exception]) {
      try {
        $this->pipeline()->accept($step3, $input);
        $this->fail($case . ': accept() takes it.');
      }
      catch (UnknownKeysException | VariantMismatchException $e) {
        $this->assertInstanceOf($exception, $e, $case);
        $this->assertSame($path, $e->getPath(), $case);
      }
      $this->assertFalse($this->validates($full, $input), $case . ': the full schema refuses it.');
      $this->assertFalse($this->validates($this->these($step3, self::EVENT, 'registration.step3')['schema'], $input), $case . ': so does the schema for these answers.');
    }

    // A module mounts: another module's object is still unknown.
    $this->enableCompliance();
    $mounted = $this->build('registration.step3');
    $input = array_replace_recursive(self::EVENT + self::mounted(NULL, 1), ['third_party_settings' => ['other' => ['a' => 1]]]);
    try {
      $this->pipeline()->accept($mounted, $input);
      $this->fail('An unmounted module\'s object is taken.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('third_party_settings', $e->getPath());
      $this->assertSame(['other'], $e->getKeys());
    }
    $this->assertFalse($this->validates($this->full($mounted, 'registration.step3'), $input));
    // Even holding nothing, the unknown module's key is refused.
    $input['third_party_settings']['other'] = NULL;
    $this->assertFalse($this->verdict($mounted, $input)[0]);

    // Inside a collection item that holds something: refused by its path.
    $this->actAsLarderKeeper();
    $cellar = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $cellar_full = $this->full($cellar, 'surface_test.cellar');
    $branded = ['racks' => [['brand' => 'red'] + self::CELLAR['racks'][0]]] + self::CELLAR;
    try {
      $this->pipeline()->accept($cellar, $branded, self::CELLAR);
      $this->fail('An unknown key inside an item is taken.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('racks.0', $e->getPath());
    }
    $this->assertFalse($this->validates($cellar_full, $branded));

    // @todo Audit section 2 (and 8): an item whose every key holds nothing
    //   is dropped as empty before its keys are read, so an unknown key in
    //   it is dropped silently rather than refused, and the schema, which
    //   refuses the key, is stricter than the pipeline.
    $blank = ['racks' => [['brand' => ''], self::CELLAR['racks'][0]]] + self::CELLAR;
    [$valid, $accepted] = $this->verdict($cellar, $blank, self::CELLAR);
    $this->assertTrue($valid);
    $this->assertCount(1, $accepted['racks']);
    $this->assertSame(self::CELLAR['racks'][0], $accepted['racks'][0]);
    $this->assertFalse($this->validates($cellar_full, $blank));

    // A map that declares no properties says nothing about its keys, and
    // neither does the schema: holds.
    $open = $this->surface(['bag' => MapDataDefinition::create()->setLabel('Bag')]);
    [$valid, $accepted] = $this->verdict($open, ['bag' => ['anything' => 1]]);
    $this->assertTrue($valid);
    $this->assertSame(['anything' => 1], $accepted['bag']);
    $this->assertTrue($this->validates($this->full($open), ['bag' => ['anything' => 1]]));
  }

  /**
   * Case 3: an untouched stale value.
   *
   * As constructed in the audit: the East room stored, the venue moved to
   * the library, the stale marker sent and no room. The marker stands for
   * the stored room, which the moved venue no longer offers, so the
   * pipeline refuses it and nothing is stored; both documents refuse the
   * submission and accept what stays stored. That holds.
   *
   * The stale value the pipeline does keep is the one a site narrowed
   * away while nothing the key watches moved. There the schema for these
   * answers refuses both the stored values it was built for and its own
   * `values`, and the full schema refuses a stored value a static list no
   * longer offers.
   */
  public function testUntouchedStaleValue(): void {
    // Store the East room.
    $east = [
      'title' => 'Spring meetup',
      'open' => TRUE,
      'venue' => 'riverside',
      'room' => 'riverside_east',
      'capacity' => 50,
    ];
    $this->assertTrue($this->submit('registration.step2', $east)->committed);
    $stored = $this->stored('registration.step2');
    $this->assertSame('riverside_east', $stored['room']);

    // The served contract for the moved venue shows the room stale.
    $step2 = $this->build('registration.step2');
    $moved = $this->these($step2, ['venue' => 'library'] + $stored, 'registration.step2');
    $this->assertSame(['room'], $moved['stale']);
    $this->assertNull($moved['values']['room']);

    // Submitted with the stale marker and no room: the marker reads as
    // the stored room, judged under the moved venue, and refused.
    $served = $this->container->get('data_surface_react.served_operations');
    $input = $served->keepStale($moved['values'], $stored, $moved['stale']);
    $this->assertSame('riverside_east', $input['room']);
    $result = $this->submit('registration.step2', $input);
    $this->assertFalse($result->committed);
    $this->assertTrue($result->violations->has('room'));
    $this->assertSame([], $result->violations->stale());
    $this->assertSame($stored, $this->stored('registration.step2'), 'Nothing is stored.');
    // The form's marker leaves the room out and lets the stored one stand:
    // the same refusal.
    $absent = $input;
    unset($absent['room']);
    $this->assertFalse($this->submit('registration.step2', $absent)->committed);
    // Both documents refuse the submission as the pipeline reads it, and
    // the schema for these answers refuses the moved values it was built
    // for, the room standing stale.
    $full = $this->full($step2, 'registration.step2');
    $this->assertFalse($this->validates($full, $input));
    $this->assertFalse($this->validates($moved['schema'], $input));
    $this->assertFalse($this->validates($moved['schema'], $moved['values']));
    // What stays stored is accepted by both: holds.
    $this->assertTrue($this->validates($full, $stored));
    $this->assertTrue($this->validates($this->these($step2, $stored, 'registration.step2')['schema'], $stored));

    // The stale value the pipeline keeps: example 4 closes the East room
    // after it was stored; the venue does not move.
    $this->enableCompliance();
    $event = ['room' => 'riverside_east'] + self::EVENT + self::mounted(NULL, 1);
    $this->assertTrue($this->submit('registration.step3', $event)->committed);
    $this->container->get('state')->set(RegistrationComplianceAlter::CLOSED_ROOMS, ['riverside_east']);
    $step3 = $this->build('registration.step3');
    $stored = $this->stored('registration.step3');
    $document = $this->these($step3, $stored, 'registration.step3');
    $this->assertSame(['room'], $document['stale']);
    $back = $served->keepStale($document['values'], $stored, $document['stale']);
    $result = $this->submit('registration.step3', $back);
    $this->assertTrue($result->committed, 'Untouched, the stale room is kept.');
    $this->assertSame(['room'], array_map(static fn ($violation): string => $violation->fullPath(), $result->violations->stale()));
    $this->assertSame('riverside_east', $this->stored('registration.step3')['room']);
    // The full schema never runs the runtime refiner: holds.
    $this->assertTrue($this->validates($this->full($step3, 'registration.step3'), $stored));
    // @todo Audit section 3: the schema for these answers is refined by the
    //   runtime refiner for the stored values, so it refuses the stored
    //   room it was built for, and its own `values` (the stale room null,
    //   and required). One-line widen listed in the audit: a stale key's
    //   schema also allows null.
    $this->assertFalse($this->validates($document['schema'], $stored));
    $this->assertFalse($this->validates($document['schema'], $document['values']));

    // A static list that no longer offers what is stored: the pipeline
    // keeps it, the full schema refuses it.
    $pick = DataDefinition::create('string')->setLabel('Pick')->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['x', 'y']]);
    $surface = $this->surface(['pick' => $pick]);
    $storage = new StateStorage($this->container->get('state'), 'data_surface_audit.pick');
    $this->container->get('state')->set('data_surface_audit.pick', ['pick' => 'z']);
    foreach ([[], ['pick' => 'z']] as $untouched) {
      $result = $this->pipeline()->submit($surface, $untouched, $storage);
      $this->assertTrue($result->committed);
      $this->assertTrue($result->violations->hasStale());
      $this->assertSame('z', $this->container->get('state')->get('data_surface_audit.pick')['pick']);
    }
    // @todo Audit section 3: the full schema states the list as it is now
    //   and cannot know what is stored; the stale rule accepts a value
    //   outside it. Recommendation: neither (state the scope).
    $this->assertFalse($this->validates($this->full($surface), ['pick' => 'z']));
    $document = $this->these($surface, ['pick' => 'z']);
    $this->assertFalse($this->validates($document['schema'], ['pick' => 'z']));
    $this->assertFalse($this->validates($document['schema'], $document['values']));
  }

  /**
   * Case 4: the Regex translation, what it carries and what it does not.
   *
   * The emitter strips the delimiters and allows only the `u` modifier;
   * any other modifier, or a bracket delimiter, leaves the pattern out and
   * names Regex under checkedOnServer, which is wider. The body is passed
   * through as written, so a PCRE construct reaches the schema verbatim.
   * opis/json-schema evaluates `pattern` with PCRE (flags `uD`), so its
   * verdicts are PCRE's with `$` meaning the very end; an ECMA engine
   * would refuse several of these patterns outright, which no test here
   * can run.
   */
  public function testRegexTranslation(): void {
    $translations = [
      // Carried.
      '/^\d{4}$/' => '^\d{4}$',
      '/^\d{4}$/u' => '^\d{4}$',
      '#^a/b$#' => '^a/b$',
      '/^a\/b$/' => '^a\/b$',
      '/^(?<y>\d{4})$/' => '^(?<y>\d{4})$',
      '/(?<=@)x$/' => '(?<=@)x$',
      '/^\p{Lu}+$/u' => '^\p{Lu}+$',
      // Left out, and named checked on the server: wider.
      '/^\d{4}$/D' => NULL,
      '/^[a-z]+$/i' => NULL,
      '/^a b$/x' => NULL,
      '/^a.b$/s' => NULL,
      '/^b$/m' => NULL,
      '{^ab$}' => NULL,
      // @todo Audit section 4: passed through verbatim though ECMA has no
      //   such construct, or reads it otherwise.
      '#^a\#b$#' => '^a\#b$',
      '/\A\d{4}\z/' => '\A\d{4}\z',
      '/^\d{4}\Z/' => '^\d{4}\Z',
      '/^(?i)[a-z]+$/' => '^(?i)[a-z]+$',
      '/^a++b$/' => '^a++b$',
      '/^(?>a+)b$/' => '^(?>a+)b$',
      '/^(?P<y>\d{4})$/' => '^(?P<y>\d{4})$',
      '/^[[:alpha:]]+$/' => '^[[:alpha:]]+$',
      '/^\x{263A}$/u' => '^\x{263A}$',
      '/^\Qa.b\E$/' => '^\Qa.b\E$',
      '/^\h\R$/' => '^\h\R$',
    ];
    foreach ($translations as $regex => $pattern) {
      $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => $regex])]);
      $property = $this->full($surface)['properties']['k'];
      $this->assertSame($pattern, $property['pattern'] ?? NULL, $regex);
      if ($pattern === NULL) {
        $this->assertSame(['Regex'], $property['x-surface']['checkedOnServer'], $regex);
      }
    }

    // `$` without D: PCRE matches before a final newline, the schema's
    // `$` only at the end. The shipped licence, through the pipeline.
    $this->enableCompliance();
    $step3 = $this->build('registration.step3');
    $licensed = ['capacity' => 150] + self::mounted("2048\n", 3) + self::EVENT;
    [$valid, $accepted] = $this->verdict($step3, $licensed, $this->stored('registration.step3'));
    $this->assertTrue($valid, 'The pipeline accepts the licence with a trailing newline.');
    $this->assertSame("2048\n", $accepted['third_party_settings']['data_surface_examples_compliance']['licence']);
    // @todo Audit section 4: every shipped pattern ends in `$` without D.
    $this->assertFalse($this->validates($this->full($step3, 'registration.step3'), $licensed));
    $this->assertFalse($this->validates($this->these($step3, $licensed, 'registration.step3')['schema'], $licensed));
    $this->assertTrue($this->validates($this->full($step3, 'registration.step3'), ['capacity' => 150] + self::mounted('2048', 3) + self::EVENT));

    // \S without u: PHP reads bytes, opis reads code points with Unicode
    // properties, and a no-break space is white space to it.
    $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => '/^\S+$/'])]);
    $this->assertTrue($this->verdict($surface, ['k' => "a\u{00A0}b"])[0]);
    // @todo Audit section 4.
    $this->assertFalse($this->validates($this->full($surface), ['k' => "a\u{00A0}b"]));

    // \W likewise, for a letter outside ASCII; an ECMA engine agrees with
    // PHP here, so this one is opis's own reading.
    $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => '/^\W+$/'])]);
    $this->assertTrue($this->verdict($surface, ['k' => 'é'])[0]);
    $this->assertFalse($this->validates($this->full($surface), ['k' => 'é']));

    // \Z: opis cannot compile the schema at all.
    $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => '/^\d{4}\Z/'])]);
    $this->assertTrue($this->verdict($surface, ['k' => '2048'])[0]);
    // @todo Audit section 4.
    try {
      $this->validates($this->full($surface), ['k' => '2048']);
      $this->fail('opis compiles a pattern with \Z.');
    }
    catch (InvalidKeywordException) {
      // As pinned.
    }

    // An inline flag: opis, being PCRE, agrees with PHP; an ECMA engine
    // refuses the pattern as a syntax error.
    $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => '/^(?i)[a-z]+$/'])]);
    $this->assertTrue($this->verdict($surface, ['k' => 'ABC'])[0]);
    $this->assertTrue($this->validates($this->full($surface), ['k' => 'ABC']));

    // A modifier left out leaves the schema wider: holds.
    $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => '/^[a-z]+$/i'])]);
    $this->assertTrue($this->verdict($surface, ['k' => 'ABC'])[0]);
    $this->assertTrue($this->validates($this->full($surface), ['k' => 'ABC']));
    $this->assertFalse($this->verdict($surface, ['k' => 'A1'])[0]);
    $this->assertTrue($this->validates($this->full($surface), ['k' => 'A1']));
  }

  /**
   * Case 5: null, the empty string, and the key absent, per scalar type.
   *
   * The pipeline accepts all three for every optional key. `''` and
   * `null` both store NULL; absent keeps what is stored. The schema
   * accepts `null` and absence everywhere, and `''` only for a plain
   * string: for a number, a boolean, a select, a map, and a string with a
   * format, a pattern or a minimum length, it refuses the empty string the
   * pipeline reads as nothing.
   */
  public function testNullEmptyStringAndAbsent(): void {
    $definitions = [
      'integer' => DataDefinition::create('integer'),
      'float' => DataDefinition::create('float'),
      'boolean' => DataDefinition::create('boolean'),
      'string' => DataDefinition::create('string'),
      'email' => DataDefinition::create('email'),
      'uri' => DataDefinition::create('uri'),
      'pattern' => DataDefinition::create('string')->addConstraint('Regex', ['pattern' => '/^\d{4}$/']),
      'length' => DataDefinition::create('string')->addConstraint('Length', ['min' => 2]),
      'select' => DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['x', 'y']]),
      'map' => MapDataDefinition::create()->setPropertyDefinition('a', DataDefinition::create('string')),
    ];
    $stored = [
      'integer' => 3,
      'float' => 1.5,
      'boolean' => TRUE,
      'string' => 'v',
      'email' => 'a@example.com',
      'uri' => 'https://example.com',
      'pattern' => '2048',
      'length' => 'ab',
      'select' => 'x',
      'map' => ['a' => 'v'],
    ];
    foreach ($definitions as $name => $definition) {
      $definition->setLabel(ucfirst($name));
    }
    $surface = $this->surface($definitions);
    $full = $this->full($surface);
    $these = $this->these($surface, $stored)['schema'];
    foreach (array_keys($definitions) as $key) {
      foreach (['""' => '', 'null' => NULL] as $spelled => $empty) {
        [$valid, $accepted] = $this->verdict($surface, [$key => $empty], $stored);
        $this->assertTrue($valid, $key . ' = ' . $spelled);
        $this->assertNull($accepted[$key], $key . ' = ' . $spelled . ' stores nothing.');
      }
      [$valid, $accepted] = $this->verdict($surface, [], $stored);
      $this->assertTrue($valid);
      $this->assertSame($stored[$key], $accepted[$key], $key . ' absent keeps what is stored.');

      foreach ([$full, $these] as $schema) {
        $this->assertTrue($this->validates($schema, [$key => NULL]), $key . ' = null');
        $this->assertTrue($this->validates($schema, []), $key . ' absent');
        // @todo Audit section 5: '' is the form's and the React text
        //   input's "nothing here"; the schema refuses it everywhere but
        //   a plain string.
        $this->assertSame($key === 'string', $this->validates($schema, [$key => '']), $key . ' = ""');
      }
    }

    // The shipped licence, cleared in the React page's text input.
    $this->enableCompliance();
    $step3 = $this->build('registration.step3');
    $cleared = self::mounted('', 1) + self::EVENT;
    [$valid, $accepted] = $this->verdict($step3, $cleared, $this->stored('registration.step3'));
    $this->assertTrue($valid);
    $this->assertNull($accepted['third_party_settings']['data_surface_examples_compliance']['licence']);
    // @todo Audit section 5.
    $this->assertFalse($this->validates($this->full($step3, 'registration.step3'), $cleared));

    // A secret is the one key where '' and null keep what is stored, as
    // absence does; the schema allows both for an optional secret: holds.
    $token = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($token);
    $vault = $this->surface(['token' => $token]);
    foreach (['', NULL] as $empty) {
      [$valid, $accepted] = $this->verdict($vault, ['token' => $empty], ['token' => 'tok']);
      $this->assertTrue($valid);
      $this->assertSame('tok', $accepted['token']);
      $this->assertTrue($this->validates($this->full($vault), ['token' => $empty]));
    }
  }

  /**
   * Case 6: a required key the pipeline accepts absent, door by door.
   *
   * The pipeline fills an absent key from what is stored, or the declared
   * default, so a partial update is accepted; the schema's `required`
   * refuses it. On the document accept() makes, it holds. The tool's
   * door refuses the partial update before the pipeline, as
   * ExamplesToolTest::testPartialUpdateIsRefusedByTheToolApi() pins; the
   * React page posts every key; the form posts every key it drew. The
   * one place it fails on the accepted document too is a required secret.
   */
  public function testRequiredKeysAbsent(): void {
    $step2 = $this->build('registration.step2');
    $stored = $this->stored('registration.step2');
    $full = $this->full($step2, 'registration.step2');
    [$valid, $accepted] = $this->verdict($step2, ['capacity' => 60], $stored);
    $this->assertTrue($valid, 'A partial update is accepted.');
    $this->assertSame($stored['title'], $accepted['title']);
    // @todo Audit section 6: stricter on the patch, holds on the document.
    $this->assertFalse($this->validates($full, ['capacity' => 60]));
    $this->assertTrue($this->validates($full, $accepted));

    // A required key nothing has stored starts from its default.
    $this->enableCompliance();
    $step3 = $this->build('registration.step3');
    $mount = $step3->getDefinitions()->get('third_party_settings');
    $this->assertNotNull($mount);
    [$valid, $accepted] = $this->verdict($step3, self::EVENT, $this->stored('registration.step3'));
    $this->assertTrue($valid);
    $this->assertSame(1, $accepted['third_party_settings']['data_surface_examples_compliance']['stewards']);
    $full3 = $this->full($step3, 'registration.step3');
    $this->assertSame(['stewards'], $full3['properties']['third_party_settings']['properties']['data_surface_examples_compliance']['required']);
    $partial = ['third_party_settings' => ['data_surface_examples_compliance' => ['licence' => NULL]]] + self::EVENT;
    // opis, as the suite runs it, fills a missing property from its
    // `default` before it reads `required` (allowDefaults), so it agrees
    // with the pipeline here; a standard validator does not.
    $this->assertTrue($this->validates($full3, $partial));
    // @todo Audit section 6.
    $this->assertFalse($this->validatesStrictly($full3, $partial));
    $this->assertTrue($this->validatesStrictly($full3, $accepted));

    // A required secret: empty keeps what is stored.
    $token = DataDefinition::create('string')->setLabel('Token')->setRequired(TRUE);
    DefinitionMetadata::setSecret($token);
    $vault = $this->surface(['token' => $token]);
    $vault_full = $this->full($vault);
    $this->assertSame('string', $vault_full['properties']['token']['type']);
    foreach (['', NULL] as $empty) {
      [$valid, $accepted] = $this->verdict($vault, ['token' => $empty], ['token' => 'tok']);
      $this->assertTrue($valid);
      $this->assertSame('tok', $accepted['token']);
    }
    $this->assertTrue($this->validates($vault_full, ['token' => '']));
    // @todo Audit section 6: null, which is what the contract's `values`
    //   carry for every secret, is refused for a required one, so the
    //   schema for these answers refuses its own values. One-line widen
    //   listed: a secret's type allows null.
    $this->assertFalse($this->validates($vault_full, ['token' => NULL]));
    $document = $this->these($vault, ['token' => 'tok']);
    $this->assertNull($document['values']['token']);
    $this->assertFalse($this->validates($document['schema'], $document['values']));
    // Never set, it is refused by both: holds.
    $this->assertFalse($this->verdict($vault, ['token' => NULL])[0]);
  }

  /**
   * Case 7: constraints JSON Schema has no keyword for.
   *
   * A constraint with no keyword and no options resolver is named under
   * checkedOnServer and changes nothing else in the schema. One with an
   * options resolver (EntityBundleExists, PluginExists, LanguageExists,
   * Country) is stated as `oneOf`, read from the same source its
   * validator reads, except PluginExists with allowFallback, whose
   * validator accepts any id where the resolver lists only the defined
   * ones.
   */
  public function testConstraintsWithNoKeyword(): void {
    $bare = $this->full($this->surface(['k' => DataDefinition::create('string')->setLabel('K')]))['properties']['k'];
    foreach ([
      'UniqueField' => [],
      'DataSurfaceUniqueNodeType' => [],
      'DataSurfaceDemoExtrasUniqueItems' => [],
      'DataSurfaceDemoExtrasReviewDeadline' => [],
      'AuditRuleNobodyDeclares' => [],
    ] as $name => $options) {
      $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint($name, $options)]);
      foreach ([$this->full($surface), $this->these($surface, [])['schema']] as $schema) {
        $property = $schema['properties']['k'];
        $this->assertSame([$name], $property['x-surface']['checkedOnServer'], $name);
        unset($property['x-surface']['checkedOnServer'], $property['x-surface']['refined'], $property['x-surface']['stale']);
        $this->assertSame(array_diff_key($bare, ['x-surface' => TRUE]), array_diff_key($property, ['x-surface' => TRUE]), $name . ': no keyword.');
      }
    }

    // A list read from the validator's own source: as narrow as the
    // validator, never narrower. The languages the site has.
    $language = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('LanguageExists', [])]);
    $schema = $this->full($language);
    $offered = array_column(array_filter($schema['properties']['k']['oneOf'], static fn (array $choice): bool => $choice['const'] !== NULL), 'const');
    $this->assertSame(array_keys($this->container->get('language_manager')->getLanguages()), $offered);
    $this->assertContains('LanguageExists', $schema['properties']['k']['x-surface']['checkedOnServer']);
    $this->assertTrue($this->verdict($language, ['k' => 'en'])[0]);
    $this->assertTrue($this->validates($schema, ['k' => 'en']));
    $this->assertFalse($this->verdict($language, ['k' => 'xx'])[0]);
    $this->assertFalse($this->validates($schema, ['k' => 'xx']));

    // PluginExists with allowFallback, on a manager with a fallback.
    $fallback = DataDefinition::create('string')->setLabel('K')->addConstraint('PluginExists', [
      'manager' => 'plugin.manager.block',
      'allowFallback' => TRUE,
    ]);
    $plugin = $this->surface(['k' => $fallback]);
    $this->assertTrue($this->verdict($plugin, ['k' => 'no_such_block'])[0]);
    // @todo Audit section 7: the resolver lists the defined plugins, the
    //   validator accepts any id. Nothing shipped sets allowFallback.
    $this->assertFalse($this->validates($this->full($plugin), ['k' => 'no_such_block']));
  }

  /**
   * Beside case 7: `format`, a keyword narrower than the validator.
   *
   * `format: email` is written for the email type and the Email
   * constraint, `format: uri` for the uri type and `format: date-time`
   * for datetime_iso8601. Drupal's email validator accepts an
   * internationalized address, the uri type only asks for a scheme, and
   * nothing in the pipeline checks a datetime_iso8601 string at all; opis
   * asserts formats and refuses all three. No shipped key is a
   * datetime_iso8601.
   */
  public function testFormatIsNarrowerThanTheValidator(): void {
    $surface = $this->surface([
      'email' => DataDefinition::create('email')->setLabel('Email'),
      'mail' => DataDefinition::create('string')->setLabel('Mail')->addConstraint('Email', []),
      'uri' => DataDefinition::create('uri')->setLabel('Uri'),
      'when' => DataDefinition::create('datetime_iso8601')->setLabel('When'),
    ]);
    $full = $this->full($surface);
    $this->assertSame('date-time', $full['properties']['when']['format']);
    foreach ([
      ['email', 'üser@example.com'],
      ['email', 'a@例子.com'],
      ['mail', 'üser@example.com'],
      ['uri', 'http://a b.example'],
      ['uri', 'http://例子.测试/'],
      ['uri', 'a:b c'],
      ['when', '2020-01-01'],
      ['when', 'tomorrow'],
    ] as [$key, $value]) {
      $this->assertTrue($this->verdict($surface, [$key => $value])[0], $key . ' = ' . $value);
      // @todo Audit, outside the ten cases: format.
      $this->assertFalse($this->validates($full, [$key => $value]), $key . ' = ' . $value);
    }
    // Both refuse the plainly malformed: holds.
    $this->assertFalse($this->verdict($surface, ['email' => 'nobody'])[0]);
    $this->assertFalse($this->validates($full, ['email' => 'nobody']));
    $this->assertFalse($this->verdict($surface, ['uri' => 'nowhere'])[0]);
    $this->assertFalse($this->validates($full, ['uri' => 'nowhere']));
  }

  /**
   * Case 8: a collection's Count against the items the pipeline drops.
   *
   * The pipeline drops an item that holds nothing (and, in a plain list, an
   * entry that holds nothing) before validate() counts; the schema counts
   * every item it is handed and holds each to the child's def. So a list
   * one blank row over `maxItems` is accepted by the pipeline and refused
   * by the schema, and a null or blank item is refused by `items`. The
   * other direction (a blank row counted towards `minItems`) is wider.
   */
  public function testCollectionsCountAfterDroppingEmptyItems(): void {
    $this->actAsLarderKeeper();
    $cellar = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $full = $this->full($cellar, 'surface_test.cellar');
    $rack = self::CELLAR['racks'][0];
    $blank = ['shelf' => NULL, 'spices' => NULL, 'label' => NULL];

    // Four racks and a blank row: four to the pipeline, five to the schema.
    $five = ['racks' => [$rack, $rack, $rack, $rack, $blank]] + self::CELLAR;
    [$valid, $accepted] = $this->verdict($cellar, $five, self::CELLAR);
    $this->assertTrue($valid);
    $this->assertCount(4, $accepted['racks']);
    // @todo Audit section 8.
    $this->assertFalse($this->validates($full, $five));
    $this->assertTrue($this->validates($full, $accepted));

    // A null item, dropped as empty; `items` refuses it.
    $with_null = ['racks' => [NULL, $rack]] + self::CELLAR;
    [$valid, $accepted] = $this->verdict($cellar, $with_null, self::CELLAR);
    $this->assertTrue($valid);
    $this->assertCount(1, $accepted['racks']);
    // @todo Audit section 8.
    $this->assertFalse($this->validates($full, $with_null));

    // Only a blank row: refused by the pipeline's Count, accepted by the
    // schema, which counts it. Wider: holds.
    $only_blank = ['racks' => [(object) []]] + self::CELLAR;
    $this->assertFalse($this->verdict($cellar, ['racks' => [[]]] + self::CELLAR, self::CELLAR)[0]);
    $this->assertTrue($this->validates($full, $only_blank));

    // An item both refuse, and one both accept: holds.
    $this->assertFalse($this->verdict($cellar, ['racks' => [['spices' => 41] + $rack]] + self::CELLAR, self::CELLAR)[0]);
    $this->assertFalse($this->validates($full, ['racks' => [['spices' => 41] + $rack]] + self::CELLAR));
    $this->assertTrue($this->validates($full, self::CELLAR));

    // A plain list with a Count: an empty entry is dropped before counting.
    $tags = (new ListDataDefinition([], DataDefinition::create('string')))->setLabel('Tags')->addConstraint('Count', ['max' => 1]);
    $surface = $this->surface(['tags' => $tags]);
    [$valid, $accepted] = $this->verdict($surface, ['tags' => ['a', '']]);
    $this->assertTrue($valid);
    $this->assertSame(['a'], $accepted['tags']);
    // @todo Audit section 8.
    $this->assertFalse($this->validates($this->full($surface), ['tags' => ['a', '']]));
  }

  /**
   * Case 9: a narrowing composed over a def.
   *
   * The compositions the emitter writes are the instance's refinement for
   * these values, which the pipeline applies too, and the locks of a
   * context. The locks are case 10. The one site found where a narrowing
   * says more than the runtime is a locked collection in the schema for
   * these answers: its items are described from the child's locked keys
   * alone, every key frozen, so each is fixed to the child's default and
   * the stored items, which the pipeline keeps, are refused.
   */
  public function testNarrowingComposition(): void {
    $this->actAsLarderKeeper('inspect spice rack');
    $cellar = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $this->assertTrue($cellar->isLocked('racks'));
    [$valid, $accepted] = $this->verdict($cellar, self::CELLAR, self::CELLAR);
    $this->assertTrue($valid);
    $this->assertSame(self::CELLAR['racks'], $accepted['racks']);
    $document = $this->these($cellar, array_replace($cellar->getDefaultValues(), self::CELLAR), 'surface_test.cellar');
    $items = SchemaResolver::inline($document['schema']['properties']['racks'], $document['schema'])['items'];
    $this->assertSame(3, $items['properties']['spices']['const']);
    $this->assertSame('Spices', $items['properties']['label']['const']);
    // @todo Audit section 9: the items are fixed to the rack's defaults,
    //   not to what is stored. The full schema writes no const there.
    $this->assertFalse($this->validates($document['schema'], self::CELLAR));
    $this->assertFalse($this->validates($document['schema'], $document['values']));
    $this->assertTrue($this->validates($this->full($cellar, 'surface_test.cellar'), self::CELLAR));

    // Refined for the values in the document, the pipeline's own reading:
    // a shelf in inches held to 80 in both.
    $this->actAsLarderKeeper();
    $open = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $shelved = ['shelves' => [['unit' => 'in', 'height' => 81]]] + self::CELLAR;
    $this->assertFalse($this->verdict($open, $shelved, self::CELLAR)[0]);
    $this->assertFalse($this->validates($this->full($open, 'surface_test.cellar'), $shelved));
  }

  /**
   * Case 10: a locked key's `const` against a posted value it ignores.
   *
   * The pipeline keeps what is stored for a locked key and ignores what is
   * posted, so a submission carrying another value is accepted and stores
   * the old one. Both documents write `const` of the value the operation
   * knows (the full schema reads the locked keys it knows), so both
   * refuse the submission.
   */
  public function testLockedKeyIgnoresPostedValue(): void {
    $this->actAsLarderKeeper();
    $cellar = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $this->assertTrue($cellar->isLocked('larder'));
    $moved = ['larder' => 'elsewhere'] + self::CELLAR;
    [$valid, $accepted] = $this->verdict($cellar, $moved, self::CELLAR);
    $this->assertTrue($valid, 'The posted value is ignored, not refused.');
    $this->assertSame('main', $accepted['larder']);
    $full = $this->full($cellar, 'surface_test.cellar');
    $document = $this->these($cellar, array_replace($cellar->getDefaultValues(), self::CELLAR), 'surface_test.cellar');
    $this->assertSame('main', $full['properties']['larder']['const']);
    $this->assertTrue($full['properties']['larder']['readOnly']);
    // @todo Audit section 10.
    $this->assertFalse($this->validates($full, $moved));
    $this->assertFalse($this->validates($document['schema'], $moved));
    // On the document accept() makes: holds.
    $this->assertTrue($this->validates($full, $accepted));
    $this->assertTrue($this->validates($document['schema'], $accepted));
  }

}
