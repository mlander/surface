<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;
use PortableSchema\Check\CheckRequest;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Tests a request's values are held to their document, by the validator.
 *
 * Each code family the document expresses, from a shipped resource where
 * one says it: its pointer, its params and its message. Then the checks:
 * a check's sub-code, a violation below the place filed there (a list
 * item's included, the place where it names nothing, no value where it
 * is a secret), a check
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
   * Tests the constraints of a map, which the document names, at any depth.
   *
   * A map's own constraint is named under `portable-check` at the map and
   * runs on the map wherever it sits: a key's map, a map inside a map,
   * the map each item of a list is. Its refusal below the map is filed
   * by the package where the check's `location` names it.
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
    $schema = $this->container->get('surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    $this->assertSame(['drupal:SurfaceExamplesExtrasReviewDeadline'], array_keys((array) $schema['properties']['deadlines']['items']['portable-check']), 'Named at each item.');
    $this->assertSame(['drupal:ComplexData'], array_keys((array) $schema['properties']['cd']['portable-check']), 'Named at the map.');
  }

  /**
   * Tests where a check's refusal below its place is filed.
   *
   * At the value its property path names, each name and index one
   * segment (`items[2].amount`); at the place where that path names no
   * value the request holds, since the package answers such a location as
   * a check that failed; and at the place, saying nothing of the value,
   * where the place below is a secret.
   */
  public function testCheckLocations(): void {
    $line = MapDataDefinition::create()->setLabel('Line');
    $line->setPropertyDefinition('amount', DataDefinition::create('integer')->setLabel('Amount'));
    $lines = ListDataDefinition::create('map')->setLabel('Lines');
    $lines->setItemDefinition($line);
    $ledger = MapDataDefinition::create()->setLabel('Ledger');
    $ledger->setPropertyDefinition('items', $lines);
    $ledger->addConstraint('Callback', ['callback' => [static::class, 'refuseTheThirdAmount']]);
    $ghost = MapDataDefinition::create()->setLabel('Ghost');
    $ghost->setPropertyDefinition('n', DataDefinition::create('integer')->setLabel('N'));
    $ghost->addConstraint('Callback', ['callback' => [static::class, 'refuseWhatIsNotThere']]);
    $token = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($token);
    $vault = MapDataDefinition::create()->setLabel('Vault');
    $vault->setPropertyDefinition('token', $token);
    $vault->addConstraint('Callback', ['callback' => [static::class, 'refuseTheToken']]);
    $definitions = ['ledger' => $ledger, 'ghost' => $ghost, 'vault' => $vault];
    $surface = new Surface(DefinitionMap::fromArrays(definitions: $definitions));
    $refused = $this->validated($surface, [
      'ledger' => ['items' => [['amount' => 1], ['amount' => 2], ['amount' => 3]]],
      'ghost' => ['n' => 1],
      'vault' => ['token' => 'hunter2-SECRET'],
    ]);
    $this->assertSame([
      '/ledger/items/2/amount check.drupal:Callback',
      '/ghost check.drupal:Callback',
      '/vault check.drupal:Callback',
    ], array_keys($refused));
    $this->assertSame('The third amount is refused.', $refused['/ledger/items/2/amount check.drupal:Callback']['message']);
    $this->assertSame('Nothing there is refused.', $refused['/ghost check.drupal:Callback']['message'], 'Filed at the place, in its own words.');
    $vaulted = $refused['/vault check.drupal:Callback'];
    $this->assertSame('This value is not accepted.', $vaulted['message']);
    $this->assertStringNotContainsString('hunter2', json_encode($vaulted, JSON_UNESCAPED_SLASHES));

    // The errors as the provider answers them: `location` only where the
    // place below holds a value and no secret.
    $values = $this->pipeline()->accept($surface, [
      'ledger' => ['items' => [['amount' => 1], ['amount' => 2], ['amount' => 3]]],
      'ghost' => ['n' => 1],
      'vault' => ['token' => 'hunter2-SECRET'],
    ]);
    $this->assertSame('/items/2/amount', $this->answered($surface, $values, '/ledger')[0]['location']);
    $this->assertArrayNotHasKey('location', $this->answered($surface, $values, '/ghost')[0], 'A path naming nothing: filed at the place.');
    $this->assertArrayNotHasKey('location', $this->answered($surface, $values, '/vault')[0], 'A secret below: filed at the place.');

    // The asked place a secret itself, with a path below it.
    $secret = DataDefinition::create('string')->setLabel('Secret');
    $secret->addConstraint('Callback', ['callback' => [static::class, 'refuseBelowTheToken']]);
    DefinitionMetadata::setSecret($secret);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['secret' => $secret]));
    $refused = $this->validated($surface, ['secret' => 'hunter2-SECRET']);
    $this->assertSame(['/secret check.drupal:Callback'], array_keys($refused));
    $this->assertSame('This value is not accepted.', $refused['/secret check.drupal:Callback']['message']);
    $this->assertStringNotContainsString('hunter2', json_encode($refused, JSON_UNESCAPED_SLASHES));
    $errors = $this->answered($surface, $this->pipeline()->accept($surface, ['secret' => 'hunter2-SECRET']), '/secret');
    $this->assertCount(1, $errors);
    $this->assertArrayNotHasKey('location', $errors[0]);
    $this->assertStringNotContainsString('hunter2', json_encode($errors, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Asks the provider for drupal:Callback at one place, as the validator does.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its accepted values.
   * @param string $pointer
   *   The place.
   *
   * @return list<array>
   *   The errors it answers.
   */
  protected function answered(SurfaceInterface $surface, array $values, string $pointer): array {
    $provider = $this->container->get('surface.portable_validator')->provider($surface, $values, $this->pipeline());
    $value = json_decode((string) json_encode(Location::definitionAt($surface, $values, $pointer)?->value), FALSE);
    [$result] = $provider->check('drupal:Callback', [new CheckRequest('drupal:Callback', $value, $pointer, new \stdClass(), NULL, NULL)]);
    $this->assertNull($result->unchecked);
    return $result->errors;
  }

  /**
   * Refuses a secret at a path below it, naming its value.
   *
   * @param mixed $value
   *   The secret.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context.
   */
  public static function refuseBelowTheToken(mixed $value, ExecutionContextInterface $context): void {
    $context->buildViolation('The token @token is refused.')->setParameter('@token', (string) $value)->atPath('inner')->addViolation();
  }

  /**
   * Refuses where the constraint's payload says, in its words.
   *
   * @param mixed $value
   *   The value.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context; the constraint's payload lists [path, message] pairs.
   */
  public static function refuseAsThePayloadSays(mixed $value, ExecutionContextInterface $context): void {
    $constraint = method_exists($context, 'getConstraint') ? $context->getConstraint() : NULL;
    foreach ($constraint instanceof Constraint ? (array) $constraint->payload : [] as [$path, $message]) {
      $context->buildViolation($message)->atPath($path)->addViolation();
    }
  }

  /**
   * Tests two messages of one check at one place are both said, once each.
   *
   * The package files errors of one location, code and params as one, so
   * the provider joins their messages, each sentence once.
   */
  public function testOneCheckTwoMessagesAtOnePlace(): void {
    $map = MapDataDefinition::create()->setLabel('M');
    $map->setPropertyDefinition('n', DataDefinition::create('integer')->setLabel('N'));
    $map->addConstraint('Callback', [
      'callback' => [static::class, 'refuseAsThePayloadSays'],
      'payload' => [['n', 'First words.'], ['n', 'Second words.'], ['n', 'First words.']],
    ]);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['m' => $map]));
    $refused = $this->validated($surface, ['m' => ['n' => 1]]);
    $this->assertSame(['/m/n check.drupal:Callback'], array_keys($refused));
    $this->assertSame('First words. Second words.', $refused['/m/n check.drupal:Callback']['message']);
  }

  /**
   * Tests a violation's path is read against the keys the map declares.
   *
   * A declared key holding brackets or a dot is one segment; brackets
   * after a list's key are an index, as Symfony writes it.
   */
  public function testPathsAreReadAgainstTheKeys(): void {
    $map = MapDataDefinition::create()->setLabel('M');
    $map->setPropertyDefinition('x[1]', DataDefinition::create('string')->setLabel('Bracketed'));
    $map->setPropertyDefinition('a.b', DataDefinition::create('string')->setLabel('Dotted'));
    $map->setPropertyDefinition('x', ListDataDefinition::create('string')->setLabel('X'));
    $map->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    $map->addConstraint('Callback', [
      'callback' => [static::class, 'refuseAsThePayloadSays'],
      'payload' => [['x[1]', 'Bracketed key.'], ['a.b', 'Dotted key.'], ['x[0]', 'First item.']],
    ]);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['m' => $map]));
    $values = $this->pipeline()->accept($surface, ['m' => ['x[1]' => 'p', 'a.b' => 'q', 'x' => ['r'], 'a' => 's']]);
    $this->assertSame(['/x[1]', '/a.b', '/x/0'], array_column($this->answered($surface, $values, '/m'), 'location'));
    // On the wire a path is dotted, so the dotted key reads as two names
    // there, as it does for every refusal.
    $refused = $this->validated($surface, $values);
    $this->assertSame('Bracketed key.', $refused['/m/x[1] check.drupal:Callback']['message'] ?? NULL);
    $this->assertSame('First item.', $refused['/m/x/0 check.drupal:Callback']['message'] ?? NULL);
  }

  /**
   * Tests a composite holding Email runs where its keywords admit the value.
   *
   * Sequentially and AtLeastOneOf are written as their parts, `allOf` and
   * `anyOf`, and named: where those parts refuse, the check is not asked,
   * so one fault is one refusal; where they admit what Drupal's Email
   * refuses (`a@b`), the check refuses it.
   */
  public function testCompositesHoldingEmail(): void {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: [
      'one' => DataDefinition::create('string')->setLabel('One')->addConstraint('AtLeastOneOf', [
        'constraints' => [['Email' => []], ['Length' => ['max' => 2]]],
      ]),
      'seq' => DataDefinition::create('string')->setLabel('Seq')->addConstraint('Sequentially', [
        'constraints' => [['NotBlank' => []], ['Email' => []]],
      ]),
    ]));
    foreach (['one' => 'anyOf', 'seq' => 'format.email'] as $key => $code) {
      $refused = $this->validated($surface, [$key => 'not an email']);
      $this->assertSame(['/' . $key . ' ' . $code], array_keys($refused), $key . ': refused once, by its keywords.');
      $refused = $this->validated($surface, [$key => 'a@b']);
      $this->assertCount(1, $refused, $key);
      $this->assertStringStartsWith('/' . $key . ' check.drupal:' . ($key === 'one' ? 'AtLeastOneOf' : 'Sequentially'), (string) array_key_first($refused), $key . ': the check refuses what the format admits.');
      $this->assertSame([], $this->validated($surface, [$key => 'news@example.com']), $key);
    }
  }

  /**
   * Tests an author's check on the items of a list runs on each item.
   *
   * The list's items are a structure, which names the check on its
   * whole: each item is refused at its own place, with the check's code.
   */
  public function testCheckOnListItems(): void {
    $name = $this->container->get('surface.resource_registry')->defineRead('test.lines', 'surface', new ResourceCallable(static::class, 'defineLines', []), 'lines');
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    $schema = json_decode((string) json_encode($this->container->get('surface.contract_emitter')->emitFull($built, 'test.lines', 'configure')->document['schema']), TRUE);
    $def = substr((string) $schema['properties']['k']['items']['$ref'], strlen('#/$defs/'));
    $this->assertArrayHasKey('drupal:Callback', $schema['$defs'][$def]['portable-check'], 'The items name the check.');
    $this->assertSame([], $this->validated($built, ['k' => [['n' => 1], ['n' => 2]]]));
    $refused = $this->validated($built, ['k' => [['n' => 1], ['n' => 7]]]);
    $this->assertSame(['/k/1 check.drupal:Callback'], array_keys($refused));
    $this->assertSame('Seven is refused.', $refused['/k/1 check.drupal:Callback']['message']);

    $notes = $this->container->get('surface.schema_reader')->notes($schema);
    $this->assertSame('Not read: a constraint the server checks is known by its plugin alone.', $notes['/$defs/' . $def . '/portable-check/drupal:Callback'] ?? NULL, 'Served, the check is noted as a plain map\'s.');
  }

  /**
   * Declares a list whose items are a map with a check, with addSchema().
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint.
   */
  public static function defineLines(BlueprintInterface $inputs): void {
    $item = Schema::object(['n' => Schema::integer()->title('N')])
      ->title('Line')
      ->with('additionalProperties', FALSE)
      ->check('drupal:Callback', ['callback' => [static::class, 'refuseSeven']]);
    $inputs->addSchema('k', Schema::list($item)->title('Lines'));
  }

  /**
   * Refuses an item holding seven.
   *
   * @param mixed $value
   *   The item.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context.
   */
  public static function refuseSeven(mixed $value, ExecutionContextInterface $context): void {
    if (is_array($value) && ($value['n'] ?? NULL) === 7) {
      $context->addViolation('Seven is refused.');
    }
  }

  /**
   * Refuses a ledger's third amount, as a constraint below its place does.
   *
   * @param mixed $value
   *   The ledger.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context.
   */
  public static function refuseTheThirdAmount(mixed $value, ExecutionContextInterface $context): void {
    if (is_array($value) && isset($value['items'][2])) {
      $context->buildViolation('The third amount is refused.')->atPath('items[2].amount')->addViolation();
    }
  }

  /**
   * Refuses a property the map does not hold.
   *
   * @param mixed $value
   *   The map.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context.
   */
  public static function refuseWhatIsNotThere(mixed $value, ExecutionContextInterface $context): void {
    $context->buildViolation('Nothing there is refused.')->atPath('missing.below')->addViolation();
  }

  /**
   * Refuses a secret below the map, naming it.
   *
   * @param mixed $value
   *   The map.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The context.
   */
  public static function refuseTheToken(mixed $value, ExecutionContextInterface $context): void {
    $token = is_array($value) ? (string) ($value['token'] ?? '') : '';
    $context->buildViolation('The token @token is refused.')->setParameter('@token', $token)->atPath('token')->addViolation();
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
