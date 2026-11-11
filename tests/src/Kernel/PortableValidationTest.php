<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Tests a request's values are held to their document, by the validator.
 *
 * Each code family the document expresses, from a shipped resource where
 * one says it: its pointer, its params and its message. Then the checks:
 * a check's sub-code, a violation below the place filed there, a check
 * the site cannot run refused as `unchecked`, a throwable in a check
 * thrown, the host's Email and Uuid beside their formats, a secret's
 * refusal that carries no value, and a check left unasked where a keyword
 * refuses. `oneOf` and `unknownProperty` are not produced by a request:
 * no shipped or built key is written as a `oneOf` of shapes, and a key
 * nothing declares is refused by accept() as `unknown_key` before the
 * validator runs. Nor are the dialect's `required` and `const`
 * (testRequiredAndConst() says why). A document's own
 * `x-portable-messages` is a unit test (PortableValidatorTest).
 *
 * @see docs/decisions.md#validation-runs-through-the-dialects-validator
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PortableValidationTest extends SurfaceKernelTestBase {

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
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_first',
    'surface_examples_retitle',
    'surface_examples_node_type',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_compliance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface_examples', 'surface_examples_first']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Makes a dry run of an operation and answers its violations.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $values
   *   The values sent.
   *
   * @return array<string, array>
   *   Each violation entry, by its pointer and code.
   */
  protected function refused(string $resource, string $operation, array $values): array {
    $run = $this->container->get('surface.runner')->run($resource, $operation, values: $values, dryRun: TRUE);
    return static::byPlace($run->result());
  }

  /**
   * Reads a result's violation entries, by pointer and code.
   *
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The result.
   *
   * @return array<string, array>
   *   Each entry, under "<pointer> <code>".
   */
  protected static function byPlace(SurfaceResult $result): array {
    $entries = [];
    foreach (ResultDocument::of($result, [])['violations'] as $entry) {
      $entries[$entry['path'] . ' ' . $entry['code']] = $entry;
    }
    return $entries;
  }

  /**
   * Validates values against a data surface built here.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values, accepted.
   *
   * @return array<string, array>
   *   Each entry, under "<pointer> <code>".
   */
  protected function validated(SurfaceInterface $surface, array $values): array {
    $pipeline = $this->pipeline();
    return static::byPlace(new SurfaceResult($values, $pipeline->validate($surface, $pipeline->accept($surface, $values))));
  }

  /**
   * Tests each keyword's code, pointer, params and message.
   */
  public function testKeywordCodes(): void {
    $bulletin = $this->refused('event.bulletin', 'configure', ['items' => 'seven', 'frequency' => 'hourly']);
    $this->assertSame(['expected' => ['integer', 'null'], 'actual' => 'string'], $bulletin['/items type']['params']);
    $this->assertSame('This value must be of type integer, string given.', $bulletin['/items type']['message']);
    // accept() refuses a list for a scalar before the validator runs, and
    // names null too, as the validator does for a key a caller may clear.
    $listed = $this->refused('event.bulletin', 'configure', ['items' => [1, 2]]);
    $this->assertSame(['expected' => ['integer', 'null'], 'actual' => 'array'], $listed['/items type']['params']);
    $this->assertSame('hourly', $bulletin['/frequency enum']['params']['value']);
    $this->assertStringStartsWith('The value you selected is not a valid choice.', $bulletin['/frequency enum']['message']);

    $bounds = $this->refused('event.bulletin', 'configure', ['items' => 0]);
    $this->assertSame(['/items minimum'], array_keys($bounds));
    $this->assertSame(['limit' => 1], $bounds['/items minimum']['params']);
    $this->assertSame(['/items maximum'], array_keys($this->refused('event.bulletin', 'configure', ['items' => 1000])));

    $catering = $this->refused('event.catering', 'configure', [
      'provider' => str_repeat('a', 300),
      'served_at' => 'noon',
    ]);
    $this->assertSame('maxLength', $catering['/provider maxLength']['code']);
    $this->assertArrayHasKey('limit', $catering['/provider maxLength']['params']);
    $this->assertArrayHasKey('/served_at pattern', $catering);

    $this->assertArrayHasKey('/sender_email format.email', $this->refused('first.newsletter', 'configure', ['sender_email' => 'not an address']));
    $this->assertArrayHasKey('/sender_email format.email', $this->refused('first.newsletter', 'configure', ['sender_email' => 'üser@example.com']), 'A non-ASCII local part is refused.');
    $this->assertArrayHasKey('/endpoint format.uri', $this->refused('event.webhook', 'configure', ['endpoint' => 'no-scheme/hook']), 'A URI with no scheme is refused.');
    $this->assertArrayHasKey('/date format.date', $this->refused('event.setup', 'configure', ['date' => '2026-02-30']));

    $retitle = $this->refused('ai.retitle', 'retitle', ['items' => [], 'style' => 'sentence']);
    $this->assertSame(['limit' => 1, 'actual' => 0], $retitle['/items minItems']['params'] ?? NULL);
    $desk = $this->refused('event.desk', 'configure', ['volunteers' => array_fill(0, 5, ['name' => 'Ann'])]);
    $this->assertSame(4, $desk['/volunteers maxItems']['params']['limit']);
  }

  /**
   * Tests the keyword codes no shipped resource says, on keys built here.
   */
  public function testKeywordCodesOfBuiltKeys(): void {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'code' => DataDefinition::create('string')->setLabel('Code')->addConstraint('Length', ['min' => 3]),
      'id' => DataDefinition::create('string')->setLabel('Id')->addConstraint('Uuid', []),
      'at' => DataDefinition::create('datetime_iso8601')->setLabel('At'),
      'tags' => ListDataDefinition::create('string')->setLabel('Tags')->addConstraint('UniqueItems', []),
      'either' => DataDefinition::create('string')->setLabel('Either')->addConstraint('AtLeastOneOf', [
        'constraints' => [
          ['Length' => ['max' => 1]],
          ['Regex' => ['pattern' => '/^z+$/D']],
        ],
      ]),
    ]));
    $refused = $this->validated($surface, [
      'code' => 'ab',
      'id' => 'not-a-uuid',
      'at' => '2026-11-14',
      'tags' => ['a', 'b', 'a'],
      'either' => 'abc',
    ]);
    $this->assertSame(['limit' => 3, 'actual' => 2], $refused['/code minLength']['params']);
    $this->assertArrayHasKey('/id format.uuid', $refused);
    $this->assertArrayHasKey('/at format.date-time', $refused);
    $this->assertSame(['indexes' => [2]], $refused['/tags uniqueItems']['params'], 'The repeated item.');
    $this->assertSame('The item "a" is listed more than once.', $refused['/tags uniqueItems']['message'], 'Said in the words of the constraint the keyword is written from.');
    // AtLeastOneOf of two keyword rows is `anyOf`, refused as itself where
    // no branch is singled out.
    $this->assertSame(['matched' => 0], $refused['/either anyOf']['params']);

    // A datetime stored with no offset is handed to the validator with Z.
    $this->assertSame([], $this->validated($surface, ['at' => '2026-11-14T16:32:00']));
  }

  /**
   * Tests a check's sub-code, and a violation below its place.
   */
  public function testChecks(): void {
    $venue = ['id' => 'riverside', 'label' => 'Riverside', 'capacity' => 100];
    $this->container->get('surface.runner')->run('event.venue', 'create', values: $venue);
    $taken = $this->refused('event.venue', 'create', ['id' => 'riverside', 'label' => 'Again', 'capacity' => 100]);
    $this->assertArrayHasKey('/id check.drupal:SurfaceUnique.taken', $taken);

    $deadline = $this->refused('node.type', 'create', [
      'name' => 'Report',
      'type' => 'report',
      'third_party_settings' => ['surface_examples_extras' => ['review_deadline' => ['amount' => 90, 'unit' => 'days']]],
    ]);
    // Filed where the check named it, the amount, with no `params.path`.
    $place = '/third_party_settings/surface_examples_extras/review_deadline/amount check.drupal:SurfaceExamplesExtrasReviewDeadline';
    $this->assertArrayHasKey($place, $deadline, implode(', ', array_keys($deadline)));
    $this->assertArrayNotHasKey('path', $deadline[$place]['params']);

    // An amount whose seconds no integer holds is the check's refusal.
    $overflow = $this->refused('node.type', 'create', [
      'name' => 'Report',
      'type' => 'report',
      'third_party_settings' => [
        'surface_examples_extras' => [
          'review_deadline' => ['amount' => PHP_INT_MAX, 'unit' => 'days'],
        ],
      ],
    ]);
    $this->assertArrayHasKey($place, $overflow, implode(', ', array_keys($overflow)));

    // A value its keywords refuse is never handed to the check: the
    // lookup SurfaceUnique makes is not made for an id too long to be one.
    $long = $this->refused('event.venue', 'create', ['id' => str_repeat('é', 33), 'label' => 'Long', 'capacity' => 100]);
    $this->assertSame(['/id maxLength', '/id pattern'], array_keys($long));
  }

  /**
   * Tests the constraints of a map the document does not name, at any depth.
   *
   * A map's own constraint runs on the map wherever it sits: a key's map,
   * a map inside a map, the map each item of a list is. Its refusal below
   * the map is filed where the check named it.
   */
  public function testMapChecks(): void {
    $deadline = function (): MapDataDefinition {
      $map = MapDataDefinition::create()->setLabel('Deadline');
      $map->setPropertyDefinition('amount', DataDefinition::create('integer')->setLabel('Amount'));
      $map->setPropertyDefinition('unit', DataDefinition::create('string')->setLabel('Unit'));
      return $map->addConstraint('SurfaceExamplesExtrasReviewDeadline', []);
    };
    $list = ListDataDefinition::create('map')->setLabel('Deadlines');
    $list->setItemDefinition($deadline());
    $complex = MapDataDefinition::create()->setLabel('Bounded');
    $complex->setPropertyDefinition('n', DataDefinition::create('integer')->setLabel('N'));
    $complex->addConstraint('ComplexData', ['n' => ['Range' => ['max' => 5]]]);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['deadlines' => $list, 'cd' => $complex]));
    $refused = $this->validated($surface, [
      'deadlines' => [['amount' => 1, 'unit' => 'days'], ['amount' => 90, 'unit' => 'days']],
      'cd' => ['n' => 9],
    ]);
    $this->assertSame([
      '/deadlines/1/amount check.drupal:SurfaceExamplesExtrasReviewDeadline',
      '/cd/n check.drupal:ComplexData.too_high',
    ], array_keys($refused));
    $this->assertStringContainsString('90 days is outside that', $refused['/deadlines/1/amount check.drupal:SurfaceExamplesExtrasReviewDeadline']['message']);
    $this->assertArrayNotHasKey('path', $refused['/cd/n check.drupal:ComplexData.too_high']['params']);
  }

  /**
   * Tests a name holding `.` is one name, for a check at it.
   */
  public function testDottedNames(): void {
    $dotted = MapDataDefinition::create()->setLabel('Dotted');
    $dotted->setPropertyDefinition('a.b', DataDefinition::create('string')->setLabel('AB')->addConstraint('ExtensionExists', ['type' => 'module']));
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'm' => $dotted,
      'x.y' => DataDefinition::create('string')->setLabel('XY')->addConstraint('ExtensionExists', ['type' => 'module']),
    ]));
    $this->assertSame([], $this->validated($surface, ['m' => ['a.b' => 'node'], 'x.y' => 'node']));
  }

  /**
   * Tests Drupal's Email and Uuid run where their formats admit a value.
   */
  public function testHostFormats(): void {
    foreach (['a@b', 'x@localhost', '"a b"@example.com', 'a@[127.0.0.1]'] as $address) {
      $refused = $this->refused('first.newsletter', 'configure', ['sender_email' => $address]);
      $codes = array_column($refused, 'code');
      $this->assertCount(1, $codes, $address . ': ' . implode(', ', array_keys($refused)));
      $this->assertStringStartsWith('check.drupal:Email', $codes[0], $address);
      $this->assertSame('/sender_email', array_column($refused, 'path')[0], $address);
      $this->assertStringContainsString('is not a valid email address', array_column($refused, 'message')[0], $address);
    }
    $this->assertSame([], $this->refused('first.newsletter', 'configure', ['sender_email' => 'news@example.com']));

    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'id' => DataDefinition::create('string')->setLabel('Id')->addConstraint('Uuid', []),
    ]));
    $refused = $this->validated($surface, ['id' => '00000000-0000-0000-0000-000000000000']);
    $this->assertNotSame([], $refused, 'The nil UUID is refused.');
    foreach ($refused as $place => $entry) {
      $this->assertStringStartsWith('/id check.drupal:Uuid.', $place);
      $this->assertStringStartsWith('This is not a valid UUID.', $entry['message']);
    }
    $this->assertSame([], $this->validated($surface, ['id' => '9b6cf0d4-1c84-4ad9-9a47-8d0c4b1b6b0e']));
  }

  /**
   * Tests a refusal at a secret carries nothing of the value sent.
   */
  public function testSecretRefusalCarriesNoValue(): void {
    $token = DataDefinition::create('string')->setLabel('Token')->addConstraint('ExtensionExists', ['type' => 'module']);
    DefinitionMetadata::setSecret($token);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['token' => $token]));
    $refused = $this->validated($surface, ['token' => 'hunter2-SECRET']);
    $this->assertCount(1, $refused);
    $entry = reset($refused);
    $this->assertSame('/token', $entry['path']);
    $this->assertSame('This value is not accepted.', $entry['message']);
    $this->assertStringNotContainsString('hunter2', json_encode($entry, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Tests an `enum` is said beside a check unless the check gave its list.
   */
  public function testEnumBesideCheck(): void {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'ext' => DataDefinition::create('string')->setLabel('Ext')
        ->addConstraint('Choice', ['choices' => ['node', 'user']])
        ->addConstraint('ExtensionExists', ['type' => 'module']),
    ]));
    $this->assertEqualsCanonicalizing(['/ext check.drupal:ExtensionExists', '/ext enum'], array_keys($this->validated($surface, ['ext' => 'zz'])), 'A list of its own, said beside the check.');
    $this->assertSame(['/ext enum'], array_keys($this->validated($surface, ['ext' => 'system'])), 'The check passes; the list refuses.');

    $alone = new Surface(DefinitionMap::fromArrays(definitions: [
      'ext' => DataDefinition::create('string')->setLabel('Ext')->addConstraint('ExtensionExists', ['type' => 'module']),
    ]));
    $this->assertSame(['/ext check.drupal:ExtensionExists'], array_keys($this->validated($alone, ['ext' => 'zz'])), 'The list the check gives is said by the check.');
  }

  /**
   * Tests `params.allowed` holds as many values as the sentence names.
   */
  public function testAllowedIsCapped(): void {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'big' => DataDefinition::create('string')->setLabel('Big')->addConstraint('Choice', ['choices' => array_map(static fn (int $i): string => 'option_' . $i, range(1, 50))]),
    ]));
    $params = $this->validated($surface, ['big' => 'zz'])['/big enum']['params'];
    $this->assertCount(10, $params['allowed']);
    $this->assertSame('option_1', $params['allowed'][0]);
    $this->assertSame(41, $params['more'], 'Forty options more, and null.');
  }

  /**
   * Tests a datetime that holds a date alone, and a datetime's storage.
   */
  public function testDateAndTimeKeys(): void {
    $date = DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly', []);
    $at = DataDefinition::create('datetime_iso8601')->setLabel('At');
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['day' => $date, 'at' => $at]));
    $schema = $this->container->get('surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    $this->assertSame('date', $schema['properties']['day']['format']);
    $this->assertSame([], $this->validated($surface, ['day' => '2026-01-01']));
    $refused = $this->validated($surface, ['day' => '2026-01-01T10:00:00Z']);
    $this->assertArrayHasKey('/day format.date', $refused);
    $this->assertArrayHasKey('/day check.drupal:DateOnly', $refused, implode(', ', array_keys($refused)));

    // A datetime with no DateTime constraint is stored in UTC with no
    // offset, and served with Z.
    $pipeline = $this->pipeline();
    $this->assertSame('2026-01-01T10:00:00', $pipeline->accept($surface, ['at' => '2026-01-01T10:00:00'])['at']);
    $moved = $pipeline->accept($surface, ['at' => '2026-01-01T10:00:00+02:00'])['at'];
    $this->assertSame('2026-01-01T08:00:00', $moved);
    $this->assertSame('2026-01-01T08:00:00Z', $pipeline->document($surface, ['at' => $moved])->at);
    $this->assertSame('2026-01-01', $pipeline->document($surface, ['day' => '2026-01-01'])->day, 'A date is served as it is stored.');
  }

  /**
   * Tests what is said of a required property, and `const`.
   *
   * The dialect's `required` is not produced by a request: accept()
   * writes every property a map declares, null where nothing was sent,
   * so a required property is present. A plain map's is said by the
   * module, in its words, at the property; an item map's null is the
   * dialect's `type`. A locked key states the value
   * the operation knows as `const`, and nothing a request sends is
   * refused by it: a value sent for a locked key is not taken, and what
   * is held there is never the request's, so the validator does not say
   * `const` at a read-only place.
   */
  public function testRequiredAndConst(): void {
    $map = MapDataDefinition::create()->setLabel('Contact');
    $map->setPropertyDefinition('email', DataDefinition::create('string')->setLabel('Contact email')->setRequired(TRUE));
    $map->setPropertyDefinition('phone', DataDefinition::create('string')->setLabel('Phone'));
    $rows = ListDataDefinition::create('map')->setLabel('Contacts');
    $rows->setItemDefinition($map);
    $fixed = DataDefinition::create('string')->setLabel('Fixed');
    DefinitionMetadata::setDefaultValue($fixed, 'a');
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['contact' => $map, 'rows' => $rows]));
    $refused = $this->validated($surface, ['contact' => ['phone' => '1'], 'rows' => [['phone' => '2']]]);
    $this->assertSame(['/contact/email required', '/rows/0/email type'], array_keys($refused));
    $this->assertSame('Contact email is required.', $refused['/contact/email required']['message']);
    $this->assertSame(['expected' => ['string'], 'actual' => 'null'], $refused['/rows/0/email type']['params']);

    $pipeline = $this->pipeline();
    $stored = ['fixed' => 'b'];
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['fixed' => $fixed], locked: ['fixed']));
    // A value sent for a locked key is not taken; the document states the
    // value the operation knows (the declared default here, which a built
    // resource seeds from storage), and the validator never says `const`
    // at a read-only place, so what storage holds is not refused either.
    $accepted = $pipeline->accept($surface, ['fixed' => 'c'], $stored);
    $this->assertSame($stored, $accepted);
    $this->assertSame('a', $this->container->get('surface.contract_emitter')->emit($surface, $accepted, 'test')->document['schema']['properties']['fixed']['const']);
    $this->assertSame([], static::byPlace(new SurfaceResult($stored, $pipeline->validate($surface, $accepted, $stored))));
  }

  /**
   * Tests a cleared map whose required keys say so is not also mistyped.
   */
  public function testClearedMapSaysItsRequiredKeys(): void {
    $inner = MapDataDefinition::create()->setLabel('Inner')->setRequired(TRUE);
    $inner->setPropertyDefinition('stewards', DataDefinition::create('integer')->setLabel('Stewards')->setRequired(TRUE));
    $outer = MapDataDefinition::create()->setLabel('Outer');
    $outer->setPropertyDefinition('inner', $inner);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['outer' => $outer]));
    $refused = $this->validated($surface, ['outer' => ['inner' => NULL]]);
    $this->assertSame(['/outer/inner/stewards required'], array_keys($refused));
  }

  /**
   * Tests a check the site cannot run is refused, and a throwable thrown.
   *
   * The throwable is thrown once the validation returns, never answered
   * as a refusal.
   */
  public function testUncheckedAndThrown(): void {
    $foreign = new Surface(DefinitionMap::fromArrays(definitions: [
      'k' => DataDefinition::create('string')->setLabel('K')->addConstraint('JsonSchema', ['schema' => ['portable-check' => ['acme:Audit' => new \stdClass()]]]),
    ]));
    $refused = $this->validated($foreign, ['k' => 'x']);
    $this->assertSame(['/k unchecked'], array_keys($refused));
    $this->assertSame('acme:Audit', $refused['/k unchecked']['params']['check']);
    $this->assertStringContainsString('acme:Audit', $refused['/k unchecked']['message']);

    $missing = new Surface(DefinitionMap::fromArrays(definitions: [
      'k' => DataDefinition::create('string')->setLabel('K')->addConstraint('AuditRuleNobodyDeclares', []),
    ]));
    $this->assertSame(['/k unchecked'], array_keys($this->validated($missing, ['k' => 'x'])), 'A plugin the site lacks never passes.');

    // EntityBundleExists throws for a value that is no string.
    $thrown = new Surface(DefinitionMap::fromArrays(definitions: [
      'k' => DataDefinition::create('integer')->setLabel('K')->addConstraint('EntityBundleExists', ['entityTypeId' => 'node']),
    ]));
    $this->expectException(UnexpectedTypeException::class);
    $this->validated($thrown, ['k' => 5]);
  }

  /**
   * Tests a keyword is said in its constraint's own message, markup kept.
   *
   * The Regex author's message, `<em>` and all, reaches every door's
   * result document as it did when typed data said it; the document's
   * `x-portable-messages` holds the same message as plain text.
   */
  public function testConstraintMessageWins(): void {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'slug' => DataDefinition::create('string')->setLabel('Slug')->addConstraint('Regex', [
        'pattern' => '/^a+$/D',
        'message' => 'Use <em>only</em> the letter a.',
      ]),
    ]));
    $schema = $this->container->get('surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    $this->assertSame('Use only the letter a.', $schema['properties']['slug']['x-portable-messages']['pattern']);
    $pipeline = $this->pipeline();
    $violations = iterator_to_array($pipeline->validate($surface, $pipeline->accept($surface, ['slug' => 'b'])), FALSE);
    $this->assertCount(1, $violations);
    $this->assertSame('pattern', $violations[0]->code);
    $this->assertInstanceOf(MarkupInterface::class, $violations[0]->message, 'Still the markup the constraint built, for each door to render.');
    $this->assertSame('Use <em>only</em> the letter a.', (string) $violations[0]->message);
  }

}
