<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Matrix;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\TypeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\VariantMismatchException;
use Drupal\data_surface\Storage\StateStorage;
use Drupal\data_surface_examples_compliance\Alter\RegistrationComplianceAlter;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\Cellar\CellarResource;
use Drupal\data_surface_resource_test\Storage\CellarStorage;
use Drupal\data_surface_resource_test\Structure\Notice\NoticeStructure;
use Drupal\Tests\data_surface\Kernel\DataSurfaceKernelTestBase;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The schema is never stricter than the runtime, on the accepted document.
 *
 * Modelled on the Tool API's AdvertisedSchemaAcceptanceTest: for each
 * example resource and the test fixtures (every scalar type, a list and a
 * map on the specimen, a slot and an attached part on example 3, a
 * runtime refiner, a declared rule and a mounted key on example 4,
 * collections on the cellar, keys that do not apply on the bulletin and
 * the notice, every declared rule's condition and effect on the parcel,
 * a structure's ports on the venue and the hall, structures mixed in on
 * the journal and attached on the ledger, a shape at two keys on the
 * depot),
 * a table of documents the pipeline accepts and documents it refuses.
 * Each row is asserted three times: against the pipeline (accept(), then
 * validate() over what is stored), against the full
 * schema (emitFull()) and against the schema for these answers (emit(),
 * built for the document), with a standard validator that asserts no
 * `format` and fills no `default`.
 *
 * The document is what accept() makes of the input, which is the scope
 * the invariant is stated on
 * (docs/decisions.md#two-documents-never-stricter-than-the-runtime); when
 * accept() refuses the input outright, the input itself. Every accepted
 * document is accepted by both schemas. A refused one is refused by both
 * where the schema is exact, and may be accepted where it says it is not
 * (`dynamic`, `runtime`, an advisory `format`): each row says which.
 *
 * The audit's cases that are no row of a table are named tests below;
 * docs/audit-never-stricter.md ("Resolved") says what each became.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class AdvertisedSchemaAcceptanceTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
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
   * The cellar as stored.
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
   * The specimen with every key answered.
   */
  protected const SPECIMEN = [
    'name' => 'Specimen',
    'word' => 'w',
    'count' => 7,
    'ratio' => 1.5,
    'flag' => TRUE,
    'mail' => 'a@example.com',
    'link' => 'https://example.com',
    'kind' => 'alpha',
    'code' => 'ab12',
    'tags' => ['a', 'b'],
    'size' => ['width' => 3, 'unit' => 'cm'],
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
    'data_surface_resource_test',
    'data_surface_tool_test',
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
   * Holds one data surface to its table of documents.
   *
   * @param string $id
   *   The resource's id, for the documents.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, built in its operation.
   * @param array $stored
   *   What storage holds.
   * @param array<string, array{0: array, 1: bool, 2?: bool, 3?: bool}> $rows
   *   By label: the input, whether the pipeline accepts it, and, for a
   *   refused one, whether the full schema and the schema for these
   *   answers accept its document anyway (both FALSE, exact, unless
   *   given).
   */
  protected function assertTable(string $id, DataSurfaceInterface $surface, array $stored, array $rows): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = $this->decoded($emitter->emitFull($surface, $id)->document['schema']);
    foreach ($rows as $label => $row) {
      [$input, $accepts] = $row;
      try {
        $document = $this->pipeline()->accept($surface, $input, $stored);
        $violations = $this->pipeline()->validate($surface, $document, $stored);
        $verdict = $violations->isEmpty();
        $these = $this->decoded($emitter->emit($surface, $document, $id)->document);
      }
      catch (UnknownKeysException | TypeMismatchException | VariantMismatchException) {
        $document = $input;
        $verdict = FALSE;
        $these = $this->decoded($emitter->emit($surface, $this->pipeline()->accept($surface, [], $stored), $id)->document);
      }
      $this->assertSame($accepts, $verdict, $label . ': the pipeline ' . ($accepts ? 'accepts' : 'refuses') . ' it.');
      $wide_full = $accepts || ($row[2] ?? FALSE);
      $wide_these = $accepts || ($row[3] ?? FALSE);
      $this->assertSame($wide_full, $this->validates($full, $document), $label . ': the full schema ' . ($wide_full ? 'accepts' : 'refuses') . ' its document.');
      $this->assertSame($wide_these, $this->validates($these['schema'], $document), $label . ': the schema for these answers ' . ($wide_these ? 'accepts' : 'refuses') . ' its document.');
      if ($accepts) {
        $this->assertTrue($this->validates($these['schema'], $these['values']), $label . ': the schema for these answers accepts its own values.');
      }
    }
  }

  /**
   * Decodes an emitted document as a client does.
   *
   * @param array $document
   *   The document.
   *
   * @return array
   *   The same, through JSON.
   */
  protected function decoded(array $document): array {
    return json_decode((string) json_encode($document), TRUE);
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
   * Reads what a resource's storage holds, in its configure operation.
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
   * Seals a data surface from definitions.
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
   * Acts as a larder keeper who may stock the racks, the cellar stored.
   *
   * @param string ...$permissions
   *   More permissions.
   */
  protected function actAsLarderKeeper(string ...$permissions): void {
    $this->setUpCurrentUser([], ['keep a larder', 'stock spice rack', ...$permissions]);
    $this->container->get('state')->set(CellarStorage::key('main'), self::CELLAR);
  }

  /**
   * The specimen: every scalar type, a list, a map.
   */
  public function testSpecimen(): void {
    $stored = ['name' => 'Stored', 'word' => 'kept', 'count' => 9];
    $this->assertTable('tool_test.specimen', $this->build('tool_test.specimen'), $stored, [
      'every key answered' => [self::SPECIMEN, TRUE],
      'only the required key' => [['name' => 'x'], TRUE],
      'a partial update' => [['count' => 60], TRUE],
      'null and the empty string clear optional keys' => [[
        'word' => NULL,
        'count' => '',
        'flag' => NULL,
        'kind' => '',
        'tags' => NULL,
        'size' => '',
      ], TRUE,
      ],
      'the notations of one document' => [[
        'word' => 5,
        'count' => '50',
        'ratio' => '1.5',
        'flag' => 'on',
        'kind' => ' Alpha ',
        'tags' => '["a"]',
        'size' => '{"width": "3", "unit": "cm"}',
      ], TRUE,
      ],
      'a whole float for an integer' => [['count' => 2.0], TRUE],
      'a boolean for an integer' => [['count' => TRUE], TRUE],
      'a single tag for a list' => [['tags' => 'x'], TRUE],
      'an empty list' => [['tags' => []], TRUE],
      'format is advisory: an internationalized address' => [['mail' => 'üser@example.com'], TRUE],
      'format is advisory: a URI with a space' => [['link' => 'http://a b.example'], TRUE],
      'below the range' => [['count' => 0], FALSE],
      'above the range' => [['count' => 1001], FALSE],
      'a fraction for an integer' => [['count' => 7.5], FALSE],
      'a fractional string for an integer' => [['count' => '1.9'], FALSE],
      'a word for an integer' => [['count' => 'seven'], FALSE],
      'a word for a boolean' => [['flag' => 'enabled'], FALSE],
      'a number beyond one for a boolean' => [['flag' => 2], FALSE],
      'a value not offered' => [['kind' => 'delta'], FALSE],
      'not the pattern' => [['code' => 'ABC1'], FALSE],
      'the pattern and a trailing newline' => [['code' => "ab12\n"], FALSE],
      'more tags than the Count' => [['tags' => ['a', 'b', 'c', 'd']], FALSE],
      'a unit not offered inside the map' => [['size' => ['unit' => 'mm']], FALSE],
      'an unknown key' => [['fax' => '1'], FALSE],
      'an unknown key inside the map' => [['size' => ['depth' => 1]], FALSE],
      'a list for a string' => [['word' => ['a']], FALSE],
      'the required key emptied' => [['name' => ''], FALSE],
      'the required key null' => [['name' => NULL], FALSE],
      // Drupal's Email check refuses it; `format` is an annotation.
      'format is advisory: not an address' => [['mail' => 'nobody'], FALSE, TRUE, TRUE],
    ]);
  }

  /**
   * Example 2: a chain of enumerated keys.
   */
  public function testExampleTwo(): void {
    $this->assertTable('registration.step2', $this->build('registration.step2'), $this->stored('registration.step2'), [
      'as stored' => [[], TRUE],
      'the harbour\'s deck' => [['venue' => 'harbour', 'room' => 'harbour_deck', 'capacity' => 90], TRUE],
      'as the form posts it' => [[
        'title' => 'Spring meetup',
        'open' => '1',
        'venue' => 'riverside',
        'room' => 'riverside_main',
        'capacity' => '120',
      ], TRUE,
      ],
      'a partial update' => [['capacity' => 60], TRUE],
      'the capacity cleared' => [['capacity' => ''], TRUE],
      'a room of another venue' => [['venue' => 'harbour', 'room' => 'library_garden'], FALSE],
      'above the room\'s ceiling' => [['room' => 'riverside_main', 'capacity' => 401], FALSE],
      'the title emptied' => [['title' => ''], FALSE],
      'a venue not offered' => [['venue' => 'nowhere'], FALSE],
    ]);
  }

  /**
   * Example 3: a slot and an attached part.
   */
  public function testExampleThree(): void {
    $this->assertTable('registration.step3', $this->build('registration.step3'), $this->stored('registration.step3'), [
      'as stored' => [[], TRUE],
      'every key answered' => [self::EVENT, TRUE],
      'paid' => [['pricing' => 'paid', 'ticket' => ['price' => 12.5, 'currency' => 'GBP']], TRUE],
      'a blank phone' => [['contact' => ['email' => 'events@example.com', 'phone' => '']], TRUE],
      'the ticket as JSON' => [['ticket' => '{"note": "Bring a mug"}'], TRUE],
      'paid with no price' => [['pricing' => 'paid', 'ticket' => ['currency' => 'EUR']], FALSE],
      'a price below a cent' => [['pricing' => 'paid', 'ticket' => ['price' => 0, 'currency' => 'EUR']], FALSE],
      'another variant\'s key' => [['pricing' => 'free', 'ticket' => ['price' => 5]], FALSE],
      'an unknown key inside the part' => [['contact' => ['email' => 'a@example.com', 'fax' => '1']], FALSE],
      'format is advisory: not an address' => [['contact' => ['email' => 'nobody']], FALSE, TRUE, TRUE],
      // A child sent null is each of its keys sent null: the optional ones
      // cleared, a required one refused in its own words.
      'the contact null, its email required' => [['contact' => NULL], FALSE],
      'the contact as a form posts nothing' => [['contact' => ''], FALSE],
      'free, the ticket null clears its note' => [['ticket' => NULL], TRUE],
      'paid, the ticket null, its price required' => [['pricing' => 'paid', 'ticket' => NULL], FALSE],
    ]);
  }

  /**
   * Example 4: a mounted key, a declared rule, a dynamic key, a runtime one.
   *
   * The capacity's ceiling without a licence is a declared rule, which
   * the full schema states exactly, so it refuses a crowd with no licence
   * as the pipeline does. The stewards watch the capacity, a number, so
   * the full schema cannot state their minimum (`dynamic`) and is wider;
   * the schema for these answers is refined for them and is exact. The
   * rooms a site closed are a runtime refiner's: never in the full schema
   * (`runtime`), in the schema for these answers.
   */
  public function testExampleFour(): void {
    $this->enableModules(['data_surface_examples_compliance']);
    $this->container->get('state')->set(RegistrationComplianceAlter::CLOSED_ROOMS, ['riverside_east']);
    $this->assertTable('registration.step3', $this->build('registration.step3'), $this->stored('registration.step3'), [
      'as stored' => [[], TRUE],
      'licensed for 150 with three stewards' => [['capacity' => 150] + self::mounted('2048', 3), TRUE],
      'the licence cleared' => [self::mounted('', 1), TRUE],
      'a licence with a trailing newline' => [['capacity' => 150] + self::mounted("2048\n", 3), FALSE],
      'over 100 with no licence' => [['capacity' => 150] + self::mounted(NULL, 3), FALSE],
      'over 100 with the licence cleared' => [['capacity' => 150] + self::mounted('', 3), FALSE],
      'a hundred with no licence' => [['capacity' => 100] + self::mounted(NULL, 2), TRUE],
      'too few stewards for 150' => [['capacity' => 150] + self::mounted('2048', 1), FALSE, TRUE, FALSE],
      'a room the site closed' => [['room' => 'riverside_east'], FALSE, TRUE, FALSE],
    ]);
  }

  /**
   * The cellar: collections, a locked key, and a refiner of a list's Count.
   */
  public function testCellar(): void {
    $this->actAsLarderKeeper();
    $rack = self::CELLAR['racks'][0];
    $blank = ['shelf' => NULL, 'spices' => NULL, 'label' => ''];
    $this->assertTable('resource_test.cellar', $this->build(CellarResource::class, 'edit', ['larder' => 'main']), self::CELLAR, [
      'as stored' => [self::CELLAR, TRUE],
      'four racks and a blank row' => [['racks' => [$rack, $rack, $rack, $rack, $blank]], TRUE],
      'the racks as JSON' => [['racks' => (string) json_encode([$rack])], TRUE],
      'one rack as a map' => [['racks' => $rack], TRUE],
      'a posted larder is ignored' => [['larder' => 'elsewhere'], TRUE],
      'a shelf in inches' => [['shelves' => [['unit' => 'in', 'height' => 80]]], TRUE],
      'five racks' => [['racks' => [$rack, $rack, $rack, $rack, $rack]], FALSE],
      'three racks when small' => [['size' => 'small', 'racks' => [$rack, $rack, $rack]], FALSE],
      'no racks' => [['racks' => []], FALSE],
      'a rack with 41 spices' => [['racks' => [['spices' => 41] + $rack]], FALSE],
      'an unknown key in a rack' => [['racks' => [['brand' => 'red'] + $rack]], FALSE],
      'an unknown key in a blank rack' => [['racks' => [['brand' => ''], $rack]], FALSE],
      'a shelf in inches over 80' => [['shelves' => [['unit' => 'in', 'height' => 81]]], FALSE],
    ]);
  }

  /**
   * The bulletin: a send day that applies only weekly, required then.
   *
   * Left out, null or as stored, a key that does not apply is accepted
   * and kept; any other value is refused, by the pipeline and by both
   * documents, which state it `false` (the full schema) or leave it out
   * (the schema for these answers), and require it only where it applies.
   */
  public function testBulletin(): void {
    $this->installConfig(['data_surface_resource_test']);
    $this->assertTable('resource_test.bulletin', $this->build('resource_test.bulletin'), $this->stored('resource_test.bulletin'), [
      'as stored' => [[], TRUE],
      'weekly, another day' => [['send_day' => 'monday'], TRUE],
      'monthly, the send day left out' => [['frequency' => 'monthly'], TRUE],
      'monthly, the send day null' => [['frequency' => 'monthly', 'send_day' => NULL], TRUE],
      'monthly, the send day as a form posts nothing' => [['frequency' => 'monthly', 'send_day' => ''], TRUE],
      'monthly, the send day as stored' => [['frequency' => 'monthly', 'send_day' => 'friday'], TRUE],
      'monthly, a send day' => [['frequency' => 'monthly', 'send_day' => 'monday'], FALSE],
      'weekly, the send day null' => [['send_day' => NULL], FALSE],
      'weekly, a day not offered' => [['send_day' => 'sunday'], FALSE],
    ]);
  }

  /**
   * The parcel: every rule between its keys declared as data.
   *
   * Each condition and each effect of the vocabulary, the alter's rules on
   * its own key and on the owner's among them. A declared rule is stated
   * exactly, so both documents hold each document to what the pipeline
   * holds it to, both ways: nothing here is `dynamic`.
   */
  public function testParcel(): void {
    $this->installConfig(['data_surface_resource_test']);
    $customs = static fn (?string $code): array => [
      'third_party_settings' => ['data_surface_resource_test' => ['courier' => 'own', 'customs' => $code]],
    ];
    $freight = ['service' => 'freight', 'weight' => 40, 'note' => 'Ring twice', 'handling' => 'cold'];
    $dear = ['weight' => 2, 'declared_value' => 2000, 'reference' => 'R1'];
    $tracked = ['service' => 'express', 'reference' => 'R1'];
    $this->assertTable('resource_test.parcel', $this->build('resource_test.parcel'), $this->stored('resource_test.parcel'), [
      'as stored' => [[], TRUE],
      'insured and signed for' => [['insured' => TRUE, 'signature' => 'Ann'], TRUE],
      'insured, signed for by nobody' => [['insured' => TRUE], FALSE],
      'kept cold by standard' => [['handling' => 'cold'], FALSE],
      'kept cold by freight, with a customs code' => [$freight + $customs('C1'), TRUE],
      'freight with no customs code' => [$freight + $customs(NULL), FALSE],
      'freight with no note' => [['note' => NULL] + $freight + $customs('C1'), FALSE],
      'a note for a standard parcel' => [['note' => 'Ring twice'], FALSE],
      'a note left empty for a standard parcel' => [['note' => ''], TRUE],
      'thirty kilograms by standard' => [['weight' => 30], FALSE],
      'two labels on a light parcel' => [['labels' => ['dry', 'heavy']], FALSE],
      'two labels on six kilograms' => [['weight' => 6, 'labels' => ['dry', 'heavy']], TRUE],
      'worth 600 under two kilograms' => [['declared_value' => 600], FALSE],
      'worth 600 at two kilograms' => [['weight' => 2, 'declared_value' => 600], TRUE],
      'worth 6000 with no reference' => [['weight' => 2, 'declared_value' => 6000], FALSE],
      'worth 6000 with a reference' => [['weight' => 2, 'declared_value' => 6000, 'reference' => 'R1'], TRUE],
      'worth 2000, signed with an initial' => [['signature' => 'A'] + $dear, FALSE],
      'worth 2000, signed with a name' => [['signature' => 'Ann'] + $dear, TRUE],
      'tracked express, a code that is none' => [['tracking' => 'nope'] + $tracked, FALSE],
      'tracked express, a code' => [['tracking' => 'AB123456'] + $tracked, TRUE],
      'untracked standard, any text' => [['reference' => 'R1', 'tracking' => 'nope'], TRUE],
      'a customs code under two kilograms' => [['weight' => 1] + $freight + $customs('C1'), FALSE],
      'no customs code under two kilograms' => [['weight' => 1] + $freight + $customs(NULL), TRUE],
      'a customs code and nothing declared' => [['declared_value' => 0] + $freight + $customs('C1'), FALSE],
    ]);
  }

  /**
   * The venue and the hall: a structure reading its container's keys.
   *
   * The seating's aisle follows the layout port, enumerated at each site;
   * its rows follow the guests port, a rule stated as written at each
   * site that wires it. Both documents hold each document to what the
   * pipeline holds it to, both ways.
   *
   * @see docs/resources.md#ports
   */
  public function testPorts(): void {
    $this->installConfig(['data_surface_resource_test']);
    $stored = ['rows' => 5, 'aisle' => 'centre', 'ushers' => 0];
    $seating = static fn (array $seating): array => ['seating' => $seating + $stored];
    $this->assertTable('resource_test.venue', $this->build('resource_test.venue'), $this->stored('resource_test.venue'), [
      'as stored' => [[], TRUE],
      'a banquet with a side aisle' => [['layout' => 'banquet'] + $seating(['aisle' => 'side']), TRUE],
      'a banquet with no aisle' => [['layout' => 'banquet'] + $seating(['aisle' => 'none']), TRUE],
      'a theatre with no aisle' => [$seating(['aisle' => 'none']), FALSE],
      'a theatre with its aisle left out' => [$seating(['aisle' => NULL]), FALSE],
      'standing, the aisle left out' => [['layout' => 'standing'] + $seating(['aisle' => NULL]), TRUE],
      'standing, with a side aisle' => [['layout' => 'standing'] + $seating(['aisle' => 'side']), FALSE],
      'twelve rows for eighty guests' => [$seating(['rows' => 12]), FALSE],
      'twelve rows for three hundred guests' => [['capacity' => 300] + $seating(['rows' => 12]), TRUE],
      'a rows count the seating does not have' => [['capacity' => 300] + $seating(['rows' => 50]), FALSE],
    ]);
    $mount = static fn (array $seating): array => [
      'third_party_settings' => ['data_surface_resource_test' => $seating + $stored],
    ];
    $chairs = static fn (array $chairs): array => ['chairs' => $chairs + ['rows' => 5]];
    $banquet = static fn (int $guests): array => ['style' => 'banquet', 'guests' => $guests];
    $this->assertTable('resource_test.hall', $this->build('resource_test.hall'), $this->stored('resource_test.hall'), [
      'as stored' => [[], TRUE],
      'chairs with a centre aisle at a banquet' => [$chairs(['rows' => 5, 'aisle' => 'centre']), FALSE],
      'chairs with a centre aisle in a theatre' => [['arrangement' => 'theatre'] + $chairs(['aisle' => 'centre']), TRUE],
      'twenty rows of chairs for fifty guests' => [['guests' => 50] + $chairs(['rows' => 20, 'aisle' => 'side']), TRUE],
      'twenty overflow rows for fifty guests' => [['guests' => 50] + $mount(['rows' => 20]), FALSE],
      'twenty overflow rows for five hundred guests' => [['guests' => 500] + $mount(['rows' => 20]), TRUE],
      'overflow with no aisle in a theatre style' => [$mount(['aisle' => 'none']), FALSE],
      'overflow with no aisle, banquet, three hundred guests' => [$banquet(300) + $mount(['aisle' => 'none']), TRUE],
      'overflow with no aisle, banquet, four hundred guests' => [$banquet(400) + $mount(['aisle' => 'none']), FALSE],
      'standing overflow, the aisle left out' => [['style' => 'standing'] + $mount(['aisle' => NULL]), TRUE],
    ]);
  }

  /**
   * The journal and the ledger: one structure mixed in, and attached.
   *
   * The journal's frame composes the defs of the structures it mixes in
   * with its own keys under `unevaluatedProperties`; the timestamps' def
   * is open there and closed where the archive attaches it, and closed in
   * the ledger, which only attaches it. Changed is at least created, a
   * coded refiner watching a number: `dynamic` in the full schema, exact
   * in the schema for these answers.
   *
   * @see docs/resources.md#mixins
   */
  public function testMixins(): void {
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
    $unseen = ['published' => FALSE, 'created' => NULL, 'changed' => NULL];
    $long = ['visibility' => 'public', 'owner' => str_repeat('a', 40), 'shared' => NULL];
    $this->assertTable('resource_test.journal', $this->build('resource_test.journal'), $this->stored('resource_test.journal'), [
      'as stored' => [[], TRUE],
      'public, a long owner, shared sent nothing' => [$long, TRUE],
      'public, shared' => [['visibility' => 'public', 'shared' => TRUE], FALSE],
      'staff, a long owner' => [['owner' => str_repeat('a', 40)], FALSE],
      'changed before created' => [['changed' => 1600000000], FALSE, TRUE, FALSE],
      'created, the changed time cleared' => [['changed' => NULL], FALSE],
      'never created, the third revision' => [$unseen, FALSE],
      'never created, the first revision' => [['revision' => 1] + $unseen, TRUE],
      'published, never created' => [['revision' => 1, 'published' => TRUE] + $unseen, FALSE],
      'an unknown key beside the members' => [['fax' => '1'], FALSE],
      'the archive public, shared as stored' => [['archive' => ['circle' => 'public', 'shared' => TRUE]], TRUE],
      'the archive public, shared changed' => [['archive' => ['circle' => 'public', 'shared' => FALSE]], FALSE],
      'the archive\'s stamps null clears them' => [['archive' => ['stamps' => NULL]], TRUE],
      'the archive\'s owner null' => [['archive' => ['owner' => NULL]], FALSE],
    ]);
    $this->assertTable('resource_test.ledger', $this->build('resource_test.ledger'), $this->stored('resource_test.ledger'), [
      'as stored' => [[], TRUE],
      'the stamps null clears them' => [['stamps' => NULL], TRUE],
      'changed before created' => [['stamps' => ['created' => 5, 'changed' => 4]], FALSE, TRUE, FALSE],
      'created, the changed time cleared' => [['stamps' => ['created' => 5, 'changed' => NULL]], FALSE],
      'an unknown key in the stamps' => [['stamps' => ['fax' => 1]], FALSE],
    ]);
  }

  /**
   * The depot: one shape at two keys, each narrowing it its own way.
   *
   * Each key refers to the stock code's def, which allows null and carries
   * the shape's pattern and the alter's maximum, and says beside it what
   * it narrows: the stock code is required and at least four long, the
   * spare code at most six. Both documents are exact, both ways.
   *
   * @see docs/decisions.md#a-shape-is-a-named-definition
   */
  public function testShapes(): void {
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->assertTable('resource_test.depot', $this->build('resource_test.depot'), $this->stored('resource_test.depot'), [
      'as stored' => [[], TRUE],
      'another stock code' => [['sku' => 'XY99'], TRUE],
      'a stock code in lowercase' => [['sku' => 'xy99'], FALSE],
      'a stock code with a trailing newline' => [['sku' => "XY99\n"], FALSE],
      'a stock code shorter than its key allows' => [['sku' => 'XY9'], FALSE],
      'a stock code longer than the shape allows' => [['sku' => 'XY' . str_repeat('9', 11)], FALSE],
      'the stock code cleared' => [['sku' => NULL], FALSE],
      'a spare code of three' => [['spare' => 'XY9'], TRUE],
      'a spare code longer than its key allows' => [['spare' => 'XY99999'], FALSE],
      'a spare code in lowercase' => [['spare' => 'xy9'], FALSE],
      'the spare code cleared' => [['spare' => NULL], TRUE],
      'a label too long for the tag' => [['tag' => ['label' => str_repeat('a', 21)]], FALSE],
    ]);
  }

  /**
   * The notice: an attached child, a slot and a mounted key, ruled out.
   */
  public function testNotice(): void {
    $notice = $this->container->get('data_surface.resources')->build(NoticeStructure::class, new OperationContext('configure'));
    $stored = [
      'channel' => 'post',
      'cadence' => 'weekly',
      'address' => ['street' => 'Main street'],
      'schedule' => ['day' => 'friday'],
      'third_party_settings' => ['data_surface_resource_test' => ['signature' => 'Ada', 'footer' => 'Sent by post']],
    ];
    $this->assertTable('resource_test.notice', $notice, $stored, [
      'by post, as stored' => [[], TRUE],
      'by email' => [['channel' => 'email'], TRUE],
      'by email, another day' => [['channel' => 'email', 'schedule' => ['day' => 'monday']], TRUE],
      'by post, the schedule null' => [['schedule' => NULL], TRUE],
      'by post, a schedule' => [['schedule' => ['day' => 'monday']], FALSE],
      'by post, a signature' => [self::signed('Bea'), FALSE],
      'by email, an address' => [['channel' => 'email', 'address' => ['street' => 'Side street']], FALSE],
      'by email, the signature null' => [['channel' => 'email'] + self::signed(NULL), FALSE],
    ]);
  }

  /**
   * The notice's mounted signature, as sent.
   *
   * @param string|null $signature
   *   The signature.
   *
   * @return array
   *   The values.
   */
  protected static function signed(?string $signature): array {
    return ['third_party_settings' => ['data_surface_resource_test' => ['signature' => $signature]]];
  }

  /**
   * Case 9: a locked list's items carry no `const`.
   *
   * The racks locked by access ('inspect spice rack') are described
   * `readOnly`, every key of every item with them, and fixed to nothing:
   * each item holds its own value, in `values`. Before, every key was
   * `const` the rack's default, so the schema for these answers refused
   * the stored racks it was built for.
   */
  public function testLockedCollectionItemsCarryNoConst(): void {
    $this->actAsLarderKeeper('inspect spice rack');
    $cellar = $this->build(CellarResource::class, 'edit', ['larder' => 'main']);
    $this->assertTrue($cellar->isLocked('racks'));
    $document = $this->decoded($this->container->get('data_surface.contract_emitter')->emit($cellar, array_replace($cellar->getDefaultValues(), self::CELLAR), 'resource_test.cellar')->document);
    $racks = $document['schema']['properties']['racks'];
    $this->assertTrue($racks['readOnly']);
    $items = SchemaResolver::inline($racks, $document['schema'])['items'];
    foreach (['shelf', 'spices', 'label'] as $key) {
      $this->assertTrue($items['properties'][$key]['readOnly'], $key);
      $this->assertArrayNotHasKey('const', $items['properties'][$key], $key);
    }
    $this->assertTrue($this->validates($document['schema'], self::CELLAR));
    $this->assertTrue($this->validates($document['schema'], $document['values']));
    // The posted racks are ignored, as for any locked key.
    $accepted = $this->pipeline()->accept($cellar, ['racks' => []] + self::CELLAR, self::CELLAR);
    $this->assertSame(self::CELLAR['racks'], $accepted['racks']);
  }

  /**
   * Case 4: the Regex translation carries only what both engines read.
   *
   * The body is carried when it stays inside the subset, with the `u`
   * and `D` modifiers; a `$` without `D`, any other modifier, a bracket
   * delimiter, `.`, and every PCRE-only construct leave `pattern` out and
   * name the Regex checked on the server. The verdicts on values follow:
   * a trailing newline is refused by both now, and every case the audit
   * found PHP and an ECMA engine to disagree on is wider, not narrower.
   */
  public function testRegexTranslation(): void {
    $translations = [
      // Carried: the subset, with D.
      '/^\d{4}$/D' => '^\d{4}$',
      '/^\d{4}$/uD' => '^\d{4}$',
      '/^[a-z0-9_]+$/D' => '^[a-z0-9_]+$',
      '/^[a-z0-9]+(?:[ -][a-z0-9]+)*$/D' => '^[a-z0-9]+(?:[ -][a-z0-9]+)*$',
      '/^\+?[0-9 ]+$/D' => '^\+?[0-9 ]+$',
      '#^a/b$#D' => '^a/b$',
      '/^a\/b$/D' => '^a\/b$',
      '/^(?<y>\d{4})-\d{2}$/D' => '^(?<y>\d{4})-\d{2}$',
      '/^\w+\s\w+$/D' => '^\w+\s\w+$',
      '/\bcat\b/' => '\bcat\b',
      '/^a{2,3}?b$/D' => '^a{2,3}?b$',
      '/^(a|b)+$/D' => '^(a|b)+$',
      // Left out: `$` without D, which PCRE reads before a final newline.
      '/^\d{4}$/' => NULL,
      '/^\d{4}$/u' => NULL,
      '#^a/b$#' => NULL,
      // Left out: modifiers ECMA has no word for, and bracket delimiters.
      '/^[a-z]+$/iD' => NULL,
      '/^a b$/xD' => NULL,
      '/^ab$/sD' => NULL,
      '/^b$/mD' => NULL,
      '{^ab$}D' => NULL,
      // Left out: constructs only PCRE has, or reads otherwise.
      '#^a\#b$#D' => NULL,
      '/\A\d{4}\z/' => NULL,
      '/^\d{4}\Z/' => NULL,
      '/^(?i)[a-z]+$/D' => NULL,
      '/^a++b$/D' => NULL,
      '/^(?>a+)b$/D' => NULL,
      '/^(?P<y>\d{4})$/D' => NULL,
      '/^[[:alpha:]]+$/D' => NULL,
      '/^\x{263A}$/uD' => NULL,
      '/^\Qa.b\E$/D' => NULL,
      '/^\h\R$/D' => NULL,
      '/^\p{Lu}+$/uD' => NULL,
      '/(?<=@)x$/D' => NULL,
      '/^\S+$/D' => NULL,
      '/^\W+$/D' => NULL,
      '/^a.b$/D' => NULL,
      '/^(a)\1$/D' => NULL,
      '/^é+$/D' => NULL,
    ];
    foreach ($translations as $regex => $pattern) {
      $constraint = ['pattern' => $regex, 'message' => 'As it says.'];
      $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', $constraint)]);
      $emitter = $this->container->get('data_surface.contract_emitter');
      $property = $this->decoded($emitter->emitFull($surface, 'audit')->document['schema'])['properties']['k'];
      $this->assertSame($pattern, $property['pattern'] ?? NULL, $regex);
      $this->assertSame('As it says.', $property['x-surface']['patternMessage'] ?? NULL, $regex . ': the message is carried either way.');
      if ($pattern === NULL) {
        $this->assertSame(['Regex'], $property['x-surface']['checkedOnServer'], $regex);
      }
    }

    // The verdicts on values, the pipeline beside both documents.
    foreach ([
      // A trailing newline, with D: refused by both.
      ['/^\d{4}$/D', "2048\n", FALSE, FALSE],
      ['/^\d{4}$/D', '2048', TRUE, TRUE],
      // Without D the pipeline accepts it, and the pattern is left out.
      ['/^\d{4}$/', "2048\n", TRUE, TRUE],
      // A no-break space: PHP reads bytes, opis and ECMA white space.
      ['/^\S+$/D', "a\u{00A0}b", TRUE, TRUE],
      // A letter outside ASCII is a word character to opis alone.
      ['/^\W+$/D', 'é', TRUE, TRUE],
      // \Z no longer reaches opis, which could not compile it.
      ['/^\d{4}\Z/', '2048', TRUE, TRUE],
      // An inline flag, left out where an ECMA engine has a syntax error.
      ['/^(?i)[a-z]+$/D', 'ABC', TRUE, TRUE],
      // A modifier left out is wider, never narrower.
      ['/^[a-z]+$/iD', 'A1', FALSE, TRUE],
    ] as [$regex, $value, $pipeline, $schema]) {
      $surface = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('Regex', ['pattern' => $regex])]);
      $label = $regex . ' ' . json_encode($value);
      $accepted = $this->pipeline()->accept($surface, ['k' => $value]);
      $this->assertSame($pipeline, $this->pipeline()->validate($surface, $accepted)->isEmpty(), $label . ': the pipeline.');
      $full = $this->decoded($this->container->get('data_surface.contract_emitter')->emitFull($surface, 'audit')->document['schema']);
      $this->assertSame($schema, $this->validates($full, $accepted), $label . ': the full schema.');
      if ($pipeline) {
        $this->assertTrue($schema, $label . ': never stricter.');
      }
    }

    // Every shipped pattern ends in `$` and carries D: the licence, with
    // example 4 on, through the pipeline and both documents.
    $this->enableModules(['data_surface_examples_compliance']);
    $step3 = $this->build('registration.step3');
    $licensed = ['capacity' => 150] + self::mounted("2048\n", 3) + self::EVENT;
    $accepted = $this->pipeline()->accept($step3, $licensed, $this->stored('registration.step3'));
    $this->assertTrue($this->pipeline()->validate($step3, $accepted)->has('third_party_settings'));
    $full = $this->decoded($this->container->get('data_surface.contract_emitter')->emitFull($step3, 'registration.step3')->document['schema']);
    // The licence is a shape, defined once, which the compliance
    // structure's key refers to.
    $this->assertSame('#/$defs/event.licence', $full['$defs']['registration.compliance']['properties']['licence']['$ref']);
    $this->assertSame('^\d{4}$', $full['$defs']['event.licence']['pattern']);
    $this->assertFalse($this->validates($full, $accepted));
  }

  /**
   * Case 3: a stale value is storage's, and the one exception.
   *
   * A stale value is served as null with its path, and sent back so: the
   * path is what reads the null as the stored value, where a null alone
   * clears an optional key. What the pipeline keeps is the stored value,
   * which the key's list no longer offers, so no schema that states the
   * list accepts it: the full schema reads no storage, and the schema for
   * these answers is refined by the refiner that narrowed it away.
   */
  public function testStaleValueIsStoragesNotAnAnswer(): void {
    // A static list that no longer offers what is stored.
    $pick = DataDefinition::create('string')->setLabel('Pick')
      ->addConstraint('Choice', ['choices' => ['x', 'y']]);
    $surface = $this->surface(['pick' => $pick]);
    $storage = new StateStorage($this->container->get('state'), 'data_surface_audit.pick');
    $this->container->get('state')->set('data_surface_audit.pick', ['pick' => 'z']);
    foreach ([[], ['pick' => 'z']] as $untouched) {
      $result = $this->pipeline()->submit($surface, $untouched, $storage);
      $this->assertTrue($result->committed, json_encode($untouched));
      $this->assertTrue($result->violations->hasStale(), json_encode($untouched));
      $this->assertSame('z', $this->container->get('state')->get('data_surface_audit.pick')['pick']);
    }
    // Null with no stale path is something said: it clears the key.
    $this->assertNull($this->pipeline()->accept($surface, ['pick' => NULL], ['pick' => 'z'])['pick']);
    $emitter = $this->container->get('data_surface.contract_emitter');
    $document = $this->decoded($emitter->emit($surface, ['pick' => 'z'], 'audit')->document);
    $this->assertSame(['pick'], $document['stale']);
    $this->assertNull($document['values']['pick']);
    // The document as served and sent back, the optional stale key null:
    // accepted, null being a value an optional key takes.
    $this->assertTrue($this->validates($document['schema'], $document['values']));
    // What is kept is storage's: outside the list, in both documents.
    $this->assertFalse($this->validates($document['schema'], ['pick' => 'z']));
    $this->assertFalse($this->validates($this->decoded($emitter->emitFull($surface, 'audit')->document['schema']), ['pick' => 'z']));

    // Example 4 closes the East room after it was stored; the venue does
    // not move. The required room is stale: its null travels with the
    // path, which is what lets the pipeline read the stored room.
    $this->enableModules(['data_surface_examples_compliance']);
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation('registration.step3', 'configure');
    $step3 = $resources->build('registration.step3', $context);
    $step3_storage = $resources->storage('registration.step3', $context, $step3);
    $event = ['room' => 'riverside_east'] + self::EVENT + self::mounted(NULL, 1);
    $this->assertTrue($this->pipeline()->submit($step3, $event, $step3_storage)->committed);
    $this->container->get('state')->set(RegistrationComplianceAlter::CLOSED_ROOMS, ['riverside_east']);
    $step3 = $resources->build('registration.step3', $context);
    $stored = $this->stored('registration.step3');
    $served = $this->decoded($emitter->emit($step3, $stored, 'registration.step3')->document);
    $this->assertSame(['room'], $served['stale']);
    $back = $this->container->get('data_surface_react.served_operations')->keepStale($served['values'], $stored, $served['stale']);
    $result = $this->pipeline()->submit($step3, $back, $resources->storage('registration.step3', $context, $step3));
    $this->assertTrue($result->committed, 'Untouched, the stale room is kept.');
    $this->assertSame('riverside_east', $this->stored('registration.step3')['room']);
    // The full schema never runs the runtime refiner: it accepts it.
    $this->assertTrue($this->validates($this->decoded($emitter->emitFull($step3, 'registration.step3')->document['schema']), $stored));
    // The schema for these answers is refined by it, and the room is
    // required: neither the stored room nor the null it is served as.
    $this->assertFalse($this->validates($served['schema'], $stored));
    $this->assertFalse($this->validates($served['schema'], $served['values']));
  }

  /**
   * Cases 5 and 6: a secret is optional, never nullable.
   *
   * Left out or null, a secret keeps what is stored, required or not; so
   * both documents leave it out of `required` and give it its declared
   * type, never null, since null does not clear it (its marker does). The
   * null its `values` carry is the masking, read as the key left out. A
   * required secret never set is still refused by the pipeline: the one
   * place the schema is wider.
   */
  public function testSecretIsOptionalNeverNullable(): void {
    foreach ([FALSE, TRUE] as $required) {
      $token = DataDefinition::create('string')->setLabel('Token')->setRequired($required);
      DefinitionMetadata::setSecret($token);
      $vault = $this->surface(['token' => $token]);
      $emitter = $this->container->get('data_surface.contract_emitter');
      $full = $this->decoded($emitter->emitFull($vault, 'audit')->document['schema']);
      $label = $required ? 'required' : 'optional';
      $this->assertSame('string', $full['properties']['token']['type'], $label);
      $this->assertTrue($full['properties']['token']['writeOnly'], $label);
      $this->assertArrayNotHasKey('required', $full, $label);
      foreach (['', NULL] as $empty) {
        $accepted = $this->pipeline()->accept($vault, ['token' => $empty], ['token' => 'tok']);
        $this->assertSame('tok', $accepted['token'], $label);
        $this->assertTrue($this->pipeline()->validate($vault, $accepted)->isEmpty(), $label);
        $this->assertTrue($this->validates($full, $accepted), $label);
      }
      // Never nullable: left out, it is accepted; null is no value of it.
      $this->assertTrue($this->validates($full, []), $label);
      $this->assertFalse($this->validates($full, ['token' => NULL]), $label);
      $document = $this->decoded($emitter->emit($vault, ['token' => 'tok'], 'audit')->document);
      $this->assertNull($document['values']['token']);
      $this->assertTrue($this->validates($document['schema'], array_diff_key($document['values'], ['token' => TRUE])), $label);
      // Never set: the pipeline refuses a required one, the schema is wider.
      $never = $this->pipeline()->accept($vault, ['token' => NULL]);
      $this->assertNull($never['token']);
      $this->assertSame(!$required, $this->pipeline()->validate($vault, $never)->isEmpty(), $label);
      $this->assertTrue($this->validates($full, []), $label);
    }
  }

  /**
   * Case 7: constraints JSON Schema has no keyword for.
   *
   * A constraint with no keyword and no options resolver is named under
   * checkedOnServer and changes nothing else. One with a resolver is
   * stated as `oneOf` read from the source its validator reads; a
   * PluginExists that allows a fallback accepts any id, so it offers no
   * list and the schema stays as wide as the validator.
   */
  public function testConstraintsWithNoKeyword(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = fn (DataSurfaceInterface $surface): array => $this->decoded($emitter->emitFull($surface, 'audit')->document['schema']);
    $bare = $full($this->surface(['k' => DataDefinition::create('string')->setLabel('K')]))['properties']['k'];
    $names = [
      'UniqueField',
      'DataSurfaceUniqueNodeType',
      'DataSurfaceDemoExtrasUniqueItems',
      'DataSurfaceDemoExtrasReviewDeadline',
      'AuditRuleNobodyDeclares',
    ];
    foreach ($names as $name) {
      $property = $full($this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint($name, [])]))['properties']['k'];
      $this->assertSame([$name], $property['x-surface']['checkedOnServer'], $name);
      $this->assertSame(array_diff_key($bare, ['x-surface' => TRUE]), array_diff_key($property, ['x-surface' => TRUE]), $name . ': no keyword.');
    }

    // The languages the site has: as narrow as the validator.
    $language = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('LanguageExists', [])]);
    $schema = $full($language);
    $offered = array_values(array_filter(array_column($schema['properties']['k']['oneOf'], 'const'), static fn (mixed $value): bool => $value !== NULL));
    $this->assertSame(array_keys($this->container->get('language_manager')->getLanguages()), $offered);
    $this->assertTrue($this->pipeline()->validate($language, ['k' => 'en'])->isEmpty());
    $this->assertTrue($this->validates($schema, ['k' => 'en']));
    $this->assertFalse($this->pipeline()->validate($language, ['k' => 'xx'])->isEmpty());
    $this->assertFalse($this->validates($schema, ['k' => 'xx']));

    // PluginExists with allowFallback on a manager that has a fallback.
    $plugin = $this->surface([
      'k' => DataDefinition::create('string')->setLabel('K')->addConstraint('PluginExists', [
        'manager' => 'plugin.manager.block',
        'allowFallback' => TRUE,
      ]),
    ]);
    $this->assertNull($this->options()->resolve($plugin->getDefinitions()->get('k')));
    $schema = $full($plugin);
    $this->assertArrayNotHasKey('oneOf', $schema['properties']['k']);
    $this->assertTrue($this->pipeline()->validate($plugin, ['k' => 'no_such_block'])->isEmpty());
    $this->assertTrue($this->validates($schema, ['k' => 'no_such_block']));
    // Without it, the defined blocks are the list, as before.
    $strict = $this->surface(['k' => DataDefinition::create('string')->setLabel('K')->addConstraint('PluginExists', ['manager' => 'plugin.manager.block'])]);
    $this->assertNotNull($this->options()->resolve($strict->getDefinitions()->get('k')));
  }

  /**
   * Case 2: an unknown key is refused in every position.
   *
   * Beside the tables' rows: inside a slot's variant and under
   * third_party_settings, mounted or not; and a map that declares no
   * properties, which says nothing about its keys, in both.
   */
  public function testUnknownKeysAreRefusedEverywhere(): void {
    $step3 = $this->build('registration.step3');
    $stored = $this->stored('registration.step3');
    foreach ([
      'inside a slot\'s variant' => [['ticket' => ['note' => '', 'fax' => '1']] + self::EVENT, 'ticket'],
      'third_party_settings, no module mounting' => [
        self::EVENT + ['third_party_settings' => ['other' => ['a' => 1]]],
        '',
      ],
    ] as $case => [$input, $path]) {
      try {
        $this->pipeline()->accept($step3, $input, $stored);
        $this->fail($case);
      }
      catch (UnknownKeysException $e) {
        $this->assertSame($path, $e->getPath(), $case);
      }
      $this->assertFalse($this->validates($this->decoded($this->container->get('data_surface.contract_emitter')->emitFull($step3, 'registration.step3')->document['schema']), $input), $case);
    }
    $this->enableModules(['data_surface_examples_compliance']);
    $mounted = $this->build('registration.step3');
    $input = array_replace_recursive(self::EVENT + self::mounted(NULL, 1), ['third_party_settings' => ['other' => NULL]]);
    try {
      $this->pipeline()->accept($mounted, $input);
      $this->fail('An unmounted module\'s key is taken, even holding nothing.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('third_party_settings', $e->getPath());
    }

    $open = $this->surface(['bag' => MapDataDefinition::create()->setLabel('Bag')]);
    $accepted = $this->pipeline()->accept($open, ['bag' => ['anything' => 1]]);
    $this->assertSame(['anything' => 1], $accepted['bag']);
    $this->assertTrue($this->validates($this->decoded($this->container->get('data_surface.contract_emitter')->emitFull($open, 'audit')->document['schema']), $accepted));
  }

}
