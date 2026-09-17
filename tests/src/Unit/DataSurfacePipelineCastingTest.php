<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\DataSurfacePipeline;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ShapeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The specification of the surface's value semantics.
 *
 * Every rule about absent, empty, NULL, required and casting is stated
 * here as a row rather than as prose, because prose is what let the
 * rules drift apart in the first place: the empty string used to mean
 * NULL for a number, the empty string for a required string, and FALSE
 * for a checkbox, all in one method. Reading this file top to bottom is
 * meant to be the same as reading docs/semantics.md, and the two are
 * expected to be checked against each other.
 *
 * The pipeline is built here with a stub typed data manager, which is
 * the point: accept() coerces without a container, so a caller that
 * holds a surface and some strings needs nothing else.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipeline
 * @see docs/semantics.md
 */
#[Group('data_surface')]
class DataSurfacePipelineCastingTest extends UnitTestCase {

  /**
   * Builds a pipeline whose constraints always pass.
   *
   * Casting happens in accept(), which never reaches the manager; the
   * stub is there so validate() can be called for the required rules
   * without a container behind it.
   *
   * The options service is never asked anything here, and cannot be: it
   * is consulted only to tell a stale value from a wrong one, which
   * happens only after a constraint has refused something, and nothing
   * these cases validate is ever refused by the stub above. So it is
   * handed over without running its constructor, rather than assembled
   * out of a plugin manager, a constraint manager and a logger that
   * would all be stubs of things this test has no opinion about. The
   * stale rule itself is a kernel test's job, because it is only true
   * if the list the pipeline reads is the list a real resolver made.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfacePipeline
   *   The pipeline under test.
   */
  protected function pipeline(): DataSurfacePipeline {
    $typed_data = $this->createMock(TypedDataInterface::class);
    $typed_data->method('validate')->willReturn(new ConstraintViolationList());
    $manager = $this->createMock(TypedDataManagerInterface::class);
    $manager->method('create')->willReturn($typed_data);
    $options = (new \ReflectionClass(DataSurfaceOptions::class))->newInstanceWithoutConstructor();
    return new DataSurfacePipeline($manager, $options, $this->getStringTranslationStub());
  }

  /**
   * Accepts one input against one definition, under the key 'value'.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to accept against.
   * @param mixed $input
   *   The raw input.
   * @param array $current
   *   The stored values, in surface shape.
   *
   * @return mixed
   *   The accepted value of the key.
   */
  protected function accept(DataDefinitionInterface $definition, mixed $input, array $current = []): mixed {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => $definition]));
    return $this->pipeline()->accept($surface, ['value' => $input], $current)['value'];
  }

  /**
   * Tests the predicate every stage shares.
   */
  #[DataProvider('configuredValues')]
  public function testIsConfigured(mixed $value, bool $expected): void {
    $this->assertSame($expected, ValueState::isConfigured($value));
  }

  /**
   * What counts as a configured value, for every data type alike.
   *
   * @return \Generator
   *   Rows of value and whether it is configured.
   */
  public static function configuredValues(): \Generator {
    // Not configured: the two ways a caller says "nothing here". The
    // empty string is in the list because that is what a browser submits
    // for an untouched text field, a cleared number, and an unchosen
    // select.
    yield 'NULL is not configured' => [NULL, FALSE];
    yield 'the empty string is not configured' => ['', FALSE];

    // Configured: everything a caller could have meant on purpose.
    yield 'FALSE is a configured value' => [FALSE, TRUE];
    yield 'TRUE is a configured value' => [TRUE, TRUE];
    yield 'the integer zero is a configured value' => [0, TRUE];
    yield 'the float zero is a configured value' => [0.0, TRUE];
    yield "the string '0' is a configured value" => ['0', TRUE];
    yield 'the empty array is a configured value' => [[], TRUE];
    yield 'a non-empty string is a configured value' => ['x', TRUE];
    yield 'a whitespace string is a configured value' => [' ', TRUE];
  }

  /**
   * Tests the casting table, one row at a time.
   */
  #[DataProvider('castingMatrix')]
  public function testCasting(DataDefinitionInterface $definition, mixed $input, mixed $expected): void {
    $this->assertSame($expected, $this->accept($definition, $input));
  }

  /**
   * The casting table: input, definition type, result.
   *
   * @return \Generator
   *   Rows of definition, raw input, and the accepted value.
   */
  public static function castingMatrix(): \Generator {
    // Rule 1, applied by accept(): input that is not configured means
    // the key holds nothing, and holding nothing is NULL for every type.
    foreach (['string', 'integer', 'float', 'boolean', 'email', 'uri', 'any'] as $type) {
      yield $type . ': NULL holds nothing' => [DataDefinition::create($type), NULL, NULL];
      yield $type . ': the empty string holds nothing' => [DataDefinition::create($type), '', NULL];
    }
    // Including the two the old rules made exceptions of: a required
    // string no longer keeps the empty value so a constraint can refuse
    // it (validate() refuses the absence itself), and an empty checkbox
    // submission is no longer read as "off".
    yield 'string: the empty string holds nothing even when required' => [
      DataDefinition::create('string')->setRequired(TRUE),
      '',
      NULL,
    ];
    yield 'boolean: the empty string is not FALSE' => [
      DataDefinition::create('boolean'),
      '',
      NULL,
    ];

    // Integer. An integer literal is an integer however it is spelled.
    yield 'integer: an integer is itself' => [DataDefinition::create('integer'), 7, 7];
    yield 'integer: zero is a value, not an absence' => [DataDefinition::create('integer'), 0, 0];
    yield "integer: the string '0' casts" => [DataDefinition::create('integer'), '0', 0];
    yield 'integer: a digit string casts' => [DataDefinition::create('integer'), '7', 7];
    yield 'integer: surrounding whitespace is trimmed' => [DataDefinition::create('integer'), " 7\n", 7];
    yield 'integer: a signed literal casts' => [DataDefinition::create('integer'), '-7', -7];
    yield 'integer: a plus sign casts' => [DataDefinition::create('integer'), '+7', 7];
    yield 'integer: leading zeros cast' => [DataDefinition::create('integer'), '007', 7];
    yield 'integer: a whole float casts' => [DataDefinition::create('integer'), 2.0, 2];
    yield 'integer: a whole float string casts' => [DataDefinition::create('integer'), '2.0', 2];
    // Casting never loses information: truncating would store a number
    // the caller did not send, so the constraint gets to refuse it.
    yield 'integer: a fractional float is left for the constraint' => [
      DataDefinition::create('integer'),
      1.9,
      1.9,
    ];
    yield 'integer: a fractional string is left for the constraint' => [
      DataDefinition::create('integer'),
      '1.9',
      '1.9',
    ];
    yield 'integer: a word is left for the constraint' => [
      DataDefinition::create('integer'),
      'seven',
      'seven',
    ];
    yield 'integer: a boolean is left for the constraint' => [
      DataDefinition::create('integer'),
      TRUE,
      TRUE,
    ];
    yield 'integer: a literal beyond the integer range is left as it arrived' => [
      DataDefinition::create('integer'),
      '9223372036854775808',
      '9223372036854775808',
    ];

    // Float. Every number is a float without losing anything.
    yield 'float: a float is itself' => [DataDefinition::create('float'), 1.5, 1.5];
    yield 'float: zero is a value, not an absence' => [DataDefinition::create('float'), 0.0, 0.0];
    yield 'float: an integer casts' => [DataDefinition::create('float'), 2, 2.0];
    yield 'float: a numeric string casts' => [DataDefinition::create('float'), '1.5', 1.5];
    yield 'float: surrounding whitespace is trimmed' => [DataDefinition::create('float'), ' 1.5 ', 1.5];
    yield 'float: exponent notation casts' => [DataDefinition::create('float'), '1e3', 1000.0];
    yield 'float: a word is left for the constraint' => [DataDefinition::create('float'), 'half', 'half'];
    yield 'float: a boolean is left for the constraint' => [DataDefinition::create('float'), TRUE, TRUE];

    // Boolean. Every notation a checkbox reaches the pipeline in, and
    // nothing else: a word that spells neither is refused rather than
    // folded into TRUE, which is what used to make 'off' arrive as on.
    yield 'boolean: TRUE is itself' => [DataDefinition::create('boolean'), TRUE, TRUE];
    yield 'boolean: FALSE is a value, not an absence' => [DataDefinition::create('boolean'), FALSE, FALSE];
    yield 'boolean: the integer 1 casts' => [DataDefinition::create('boolean'), 1, TRUE];
    yield 'boolean: the integer 0 casts' => [DataDefinition::create('boolean'), 0, FALSE];
    yield "boolean: the string '1' casts" => [DataDefinition::create('boolean'), '1', TRUE];
    yield "boolean: the string '0' casts" => [DataDefinition::create('boolean'), '0', FALSE];
    yield "boolean: 'true' casts" => [DataDefinition::create('boolean'), 'true', TRUE];
    yield "boolean: 'false' casts" => [DataDefinition::create('boolean'), 'false', FALSE];
    yield "boolean: 'on' casts" => [DataDefinition::create('boolean'), 'on', TRUE];
    yield "boolean: 'off' casts" => [DataDefinition::create('boolean'), 'off', FALSE];
    yield "boolean: 'yes' casts" => [DataDefinition::create('boolean'), 'yes', TRUE];
    yield "boolean: 'no' casts" => [DataDefinition::create('boolean'), 'no', FALSE];
    yield 'boolean: case and whitespace do not matter' => [
      DataDefinition::create('boolean'),
      " OFF\t",
      FALSE,
    ];
    yield 'boolean: another word is left for the constraint' => [
      DataDefinition::create('boolean'),
      'maybe',
      'maybe',
    ];
    yield 'boolean: another number is left for the constraint' => [
      DataDefinition::create('boolean'),
      2,
      2,
    ];

    // String, email and uri share one rule: a scalar is that scalar
    // written out.
    yield 'string: a string is itself' => [DataDefinition::create('string'), 'x', 'x'];
    yield "string: '0' is a value, not an absence" => [DataDefinition::create('string'), '0', '0'];
    yield 'string: an integer casts' => [DataDefinition::create('string'), 7, '7'];
    yield 'string: a float casts' => [DataDefinition::create('string'), 1.5, '1.5'];
    yield 'string: TRUE casts the way PHP writes it' => [DataDefinition::create('string'), TRUE, '1'];
    // FALSE writes out as the empty string, which holds nothing, so the
    // key holds nothing.
    yield 'string: FALSE writes out as nothing, so it holds nothing' => [
      DataDefinition::create('string'),
      FALSE,
      NULL,
    ];
    yield 'email: a scalar casts like a string' => [DataDefinition::create('email'), 7, '7'];
    yield 'email: an address passes through' => [
      DataDefinition::create('email'),
      'somebody@example.com',
      'somebody@example.com',
    ];
    yield 'uri: an address passes through' => [
      DataDefinition::create('uri'),
      'https://example.com',
      'https://example.com',
    ];

    // 'any' is the declared escape hatch, so nothing is done to it.
    yield 'any: a string is untouched' => [DataDefinition::create('any'), '7', '7'];
    yield 'any: a boolean is untouched' => [DataDefinition::create('any'), FALSE, FALSE];
    yield 'any: an array is untouched' => [
      DataDefinition::create('any'),
      ['whatever' => ['shape' => 1]],
      ['whatever' => ['shape' => 1]],
    ];
  }

  /**
   * Tests the shapes a definition cannot hold at all.
   */
  #[DataProvider('shapeMismatches')]
  public function testShapeMismatchIsRefused(DataDefinitionInterface $definition, mixed $input, string $expected, string $actual): void {
    try {
      $this->accept($definition, $input);
      $this->fail('Expected a ShapeMismatchException.');
    }
    catch (ShapeMismatchException $e) {
      $this->assertSame('value', $e->getPath());
      $this->assertSame($expected, $e->getExpected());
      $this->assertSame($actual, $e->getActual());
    }
  }

  /**
   * Input that no cast can rescue, per definition shape.
   *
   * @return \Generator
   *   Rows of definition, raw input, expected type name, actual type
   *   name.
   */
  public static function shapeMismatches(): \Generator {
    yield 'an array where a string was advertised' => [
      DataDefinition::create('string'),
      ['a', 'b'],
      'string',
      'array',
    ];
    yield 'an array where a number was advertised' => [
      DataDefinition::create('integer'),
      [1],
      'integer',
      'array',
    ];
    yield 'an array where a checkbox was advertised' => [
      DataDefinition::create('boolean'),
      [],
      'boolean',
      'array',
    ];
    yield 'a string where a list was advertised' => [
      static::listDefinition(),
      'us',
      'list',
      'string',
    ];
    yield 'a number where a list was advertised' => [
      static::listDefinition(),
      7,
      'list',
      'int',
    ];
    yield 'a string where a map was advertised' => [
      static::mapDefinition(),
      'star',
      'map',
      'string',
    ];
    yield 'a number where a map was advertised' => [
      static::mapDefinition(),
      1,
      'map',
      'int',
    ];
  }

  /**
   * Tests that a list normalizes to a clean list of cast items.
   */
  #[DataProvider('listNormalization')]
  public function testListNormalization(mixed $input, mixed $expected): void {
    $this->assertSame($expected, $this->accept(static::listDefinition(), $input));
  }

  /**
   * Every shape a list arrives in, and the one shape it leaves in.
   *
   * @return \Generator
   *   Rows of raw input and the accepted list.
   */
  public static function listNormalization(): \Generator {
    yield 'a plain list passes through' => [['us', 'ca'], ['us', 'ca']];
    // A multiple select submits the chosen values keyed by themselves.
    yield 'keys are dropped and the list reindexed' => [
      ['us' => 'us', 'ca' => 'ca'],
      ['us', 'ca'],
    ];
    yield 'gaps are closed' => [[3 => 'us', 7 => 'ca'], ['us', 'ca']];
    // An unchosen select option is submitted like any other value.
    yield 'entries that hold nothing are not items' => [
      ['us', '', NULL, 'ca'],
      ['us', 'ca'],
    ];
    yield 'an empty list is an empty list, not an absence' => [[], []];
    // The items go through the casting table like any other value, so a
    // list of strings keeps a digit string a string.
    yield 'items are cast through the item definition' => [['7', 8], ['7', '8']];
  }

  /**
   * Tests that a list nobody spoke about keeps what it held.
   *
   * The one place where input that holds nothing does not mean NULL: a
   * form that rendered no list widget, or a payload that named no list,
   * has said nothing about the list rather than emptied it.
   */
  public function testAbsentListKeepsWhatItHeld(): void {
    $definition = static::listDefinition();
    DefinitionMetadata::setDefaultValue($definition, ['us']);

    $this->assertSame(['us'], $this->accept($definition, NULL));
    $this->assertSame(['us'], $this->accept($definition, ''));
    $this->assertSame(['ca'], $this->accept($definition, '', ['value' => ['ca']]));
    // Emptying it is said with an empty list, which is a value.
    $this->assertSame([], $this->accept($definition, [], ['value' => ['ca']]));
  }

  /**
   * Tests that items cast through the item definition, recursing.
   */
  public function testListItemsCastThroughTheItemDefinition(): void {
    $list = new ListDataDefinition([], DataDefinition::create('integer'));
    $this->assertSame([1, 2], $this->accept($list, ['1', ' 2 ']));

    $item = MapDataDefinition::create();
    $item->setPropertyDefinition('weight', DataDefinition::create('integer'));
    $this->assertSame(
      [['weight' => 3], ['weight' => 4]],
      $this->accept(new ListDataDefinition([], $item), [['weight' => '3'], ['weight' => '4']]),
    );
  }

  /**
   * Tests the merge order inside a map: defaults, current, input.
   */
  public function testMapMergesDefaultsThenCurrentThenInput(): void {
    $definition = static::mapDefinition();

    // Nothing stored: the declared property defaults stand, and the
    // input moves only what it names.
    $this->assertSame(
      ['badge' => 'flame', 'weight' => 4],
      $this->accept($definition, ['weight' => '4']),
    );
    // Stored values sit between the defaults and the input.
    $this->assertSame(
      ['badge' => 'star', 'weight' => 4],
      $this->accept($definition, ['weight' => '4'], ['value' => ['badge' => 'star', 'weight' => 2]]),
    );
    // A property the stored map never held still starts from its
    // default.
    $this->assertSame(
      ['badge' => 'flame', 'weight' => 2],
      $this->accept($definition, [], ['value' => ['weight' => 2]]),
    );
    // A property submitted empty holds nothing, and says so.
    $this->assertSame(
      ['badge' => NULL, 'weight' => NULL],
      $this->accept($definition, ['badge' => '', 'weight' => ''], ['value' => ['badge' => 'star', 'weight' => 2]]),
    );
  }

  /**
   * Tests that a key only storage knows about stays in storage.
   *
   * A complex definition with declared properties says what the map
   * holds. Anything else in the stored array belongs to whatever wrote
   * it, and carrying it back out of the surface would make the surface
   * responsible for a value it never advertised.
   */
  public function testUndeclaredStoredKeysDoNotRoundTrip(): void {
    $accepted = $this->accept(
      static::mapDefinition(),
      ['weight' => 1],
      ['value' => ['badge' => 'star', 'weight' => 9, 'legacy' => 'kept elsewhere']],
    );
    $this->assertSame(['badge' => 'star', 'weight' => 1], $accepted);
  }

  /**
   * Tests that a map holding nothing is NULL, unlike a list.
   */
  public function testMapHoldingNothingIsNull(): void {
    $this->assertNull($this->accept(static::mapDefinition(), ''));
    $this->assertNull($this->accept(static::mapDefinition(), NULL));
  }

  /**
   * Tests that a map with no declared properties is left alone.
   *
   * There is nothing to recurse into and nothing to refuse: a map that
   * declares no properties describes a bag of values it has said nothing
   * about, so the bag passes through as it arrived.
   */
  public function testMapWithoutPropertiesPassesThrough(): void {
    $this->assertSame(
      ['anything' => ['at' => 'all']],
      $this->accept(MapDataDefinition::create(), ['anything' => ['at' => 'all']]),
    );
  }

  /**
   * Tests that an undeclared input key is refused at any depth.
   */
  public function testUnknownPropertyIsRefused(): void {
    try {
      $this->accept(static::mapDefinition(), ['badge' => 'star', 'bogus' => 1]);
      $this->fail('Expected an UnknownKeysException.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame(['bogus'], $e->getKeys());
      $this->assertSame('value', $e->getPath());
    }
  }

  /**
   * Tests that a locked key takes storage first and the default second.
   */
  public function testLockedKeyPrefersWhatStorageHolds(): void {
    $definition = DataDefinition::create('string')->setLabel('Flavor');
    DefinitionMetadata::setDefaultValue($definition, 'vanilla');
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: ['value' => $definition],
        locked: ['value'],
      ),
    );

    // Nothing stored: the declared default is where the key starts.
    $this->assertSame(
      ['value' => 'vanilla'],
      $this->pipeline()->accept($surface, []),
    );
    // Stored: the stored value is the one value the key may hold, and
    // input cannot move it — which is what stopped an edit form from
    // resetting a locked key to its default.
    $this->assertSame(
      ['value' => 'chocolate'],
      $this->pipeline()->accept($surface, ['value' => 'strawberry'], ['value' => 'chocolate']),
    );
    // A stored NULL is a stored value, not an absence.
    $this->assertSame(
      ['value' => NULL],
      $this->pipeline()->accept($surface, ['value' => 'strawberry'], ['value' => NULL]),
    );
  }

  /**
   * Tests the one exception: a secret keeps what it holds.
   *
   * Every other key in the module reads NULL and the empty string as
   * "this key holds nothing". A secret cannot, because the form that
   * collects it can never echo the stored value, so the box comes up
   * empty whether or not there is a secret behind it and an untouched
   * box would otherwise erase one.
   */
  #[DataProvider('secretInputs')]
  public function testSecretKeepsWhatItHolds(mixed $input, mixed $expected): void {
    $definition = DataDefinition::create('string')->setLabel('API key');
    DefinitionMetadata::setSecret($definition);
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => $definition]));

    $this->assertSame(
      ['value' => $expected],
      $this->pipeline()->accept($surface, ['value' => $input], ['value' => 'stored-token']),
    );
  }

  /**
   * What a secret key does with each kind of input.
   *
   * @return \Generator
   *   Rows of raw input and the accepted value, against a stored secret.
   */
  public static function secretInputs(): \Generator {
    // The keep rule, which is the whole exception.
    yield 'NULL keeps the stored secret' => [NULL, 'stored-token'];
    yield 'the empty string keeps the stored secret' => ['', 'stored-token'];

    // The escape from it, said out loud.
    yield 'the clear marker empties the secret' => [
      DataSurfacePipelineInterface::CLEAR_SECRET,
      NULL,
    ];

    // Everything else is an ordinary string, cast the ordinary way.
    yield 'a new secret replaces the stored one' => ['fresh-token', 'fresh-token'];
    yield 'a secret is cast like any other string' => [7, '7'];
    yield 'whitespace is content here too' => [' ', ' '];
  }

  /**
   * Tests that a secret with nothing stored starts from its default.
   *
   * The keep rule keeps whatever the key holds, and for a key storage
   * has never held that is the declared default, exactly as for any
   * other key. Which is also why a required secret that has never been
   * set and is left blank is refused: it holds NULL like anything else.
   */
  public function testSecretWithNothingStored(): void {
    $definition = DataDefinition::create('string')->setLabel('API key')->setRequired(TRUE);
    DefinitionMetadata::setSecret($definition);
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => $definition]));
    $pipeline = $this->pipeline();

    $accepted = $pipeline->accept($surface, ['value' => '']);
    $this->assertSame(['value' => NULL], $accepted);
    $errors = $pipeline->validate($surface, $accepted);
    $this->assertCount(1, $errors->byKey('value'));

    // And once it holds something, the empty box is a legal submission.
    $this->assertSame(
      ['value' => 'stored-token'],
      $pipeline->accept($surface, ['value' => ''], ['value' => 'stored-token']),
    );
    $this->assertCount(0, $pipeline->validate($surface, ['value' => 'stored-token']));
  }

  /**
   * Tests that locked beats secret when a key is both.
   *
   * They say different things — locked is about input, secret is about
   * output — so a key may be both, and then the locked rule stands: a
   * value space narrowed to one value is not widened by the marker that
   * empties a secret.
   */
  public function testLockedBeatsSecret(): void {
    $definition = DataDefinition::create('string')->setLabel('API key');
    DefinitionMetadata::setSecret($definition);
    $surface = new DataSurface(
      DefinitionMap::fromArrays(definitions: ['value' => $definition], locked: ['value']),
    );

    $this->assertSame(
      ['value' => 'stored-token'],
      $this->pipeline()->accept(
        $surface,
        ['value' => DataSurfacePipelineInterface::CLEAR_SECRET],
        ['value' => 'stored-token'],
      ),
    );
  }

  /**
   * Tests that the clear marker means nothing to an ordinary key.
   *
   * It is the pipeline's spelling for one act on one kind of key, not a
   * reserved word every string has to avoid.
   */
  public function testClearMarkerIsAnOrdinaryStringElsewhere(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(definitions: ['value' => DataDefinition::create('string')]),
    );

    $this->assertSame(
      ['value' => DataSurfacePipelineInterface::CLEAR_SECRET],
      $this->pipeline()->accept($surface, ['value' => DataSurfacePipelineInterface::CLEAR_SECRET]),
    );
  }

  /**
   * Tests that a key the input never names keeps what it held.
   */
  public function testAbsentKeyKeepsWhatItHeld(): void {
    $definition = DataDefinition::create('string');
    DefinitionMetadata::setDefaultValue($definition, 'vanilla');
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => $definition]));

    $this->assertSame(['value' => 'vanilla'], $this->pipeline()->accept($surface, []));
    $this->assertSame(
      ['value' => 'chocolate'],
      $this->pipeline()->accept($surface, [], ['value' => 'chocolate']),
    );
  }

  /**
   * Tests required, which is configured and nothing else.
   */
  #[DataProvider('requiredValues')]
  public function testRequiredMeansConfigured(mixed $value, bool $violates): void {
    $definition = DataDefinition::create('any')->setLabel('Title')->setRequired(TRUE);
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => $definition]));
    $errors = $this->pipeline()->validate($surface, ['value' => $value]);

    if (!$violates) {
      $this->assertCount(0, $errors);
      return;
    }
    $this->assertCount(1, $errors->byKey('value'));
    $this->assertSame('', $errors->byKey('value')[0]->path);
    $message = $errors->byKey('value')[0]->message;
    // The surface's own message, as a translatable string with the label
    // as a placeholder, rather than whichever message a type-specific
    // constraint would have produced.
    $this->assertInstanceOf(TranslatableMarkup::class, $message);
    $this->assertSame('@label is required.', $message->getUntranslatedString());
    $this->assertSame(['@label' => 'Title'], $message->getArguments());
  }

  /**
   * What a required key accepts, which is every configured value.
   *
   * @return \Generator
   *   Rows of value and whether the required check refuses it.
   */
  public static function requiredValues(): \Generator {
    yield 'NULL is missing' => [NULL, TRUE];
    yield 'the empty string is missing' => ['', TRUE];
    yield 'a string is present' => ['x', FALSE];
    // The deliberate reading: a required checkbox that is off, a
    // required zero, and a required list or map with nothing in it are
    // answers. A surface that wants more than an answer says so with a
    // constraint of its own.
    yield 'FALSE is an answer' => [FALSE, FALSE];
    yield 'zero is an answer' => [0, FALSE];
    yield "the string '0' is an answer" => ['0', FALSE];
    yield 'an empty list is an answer' => [[], FALSE];
  }

  /**
   * Tests that the required message names the key when there is no label.
   */
  public function testRequiredMessageFallsBackToTheKey(): void {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['value' => DataDefinition::create('string')->setRequired(TRUE)]));
    $errors = $this->pipeline()->validate($surface, []);
    $message = $errors->byKey('value')[0]->message;
    $this->assertInstanceOf(TranslatableMarkup::class, $message);
    $this->assertSame(['@label' => 'value'], $message->getArguments());
  }

  /**
   * Builds the list definition the list rows use: optional countries.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   A list of strings.
   */
  protected static function listDefinition(): ListDataDefinition {
    $list = new ListDataDefinition([], DataDefinition::create('string'));
    $list->setLabel('Countries');
    return $list;
  }

  /**
   * Builds the map definition the map rows use: a badge and a weight.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   A map with two declared properties, one of them with a default.
   */
  protected static function mapDefinition(): MapDataDefinition {
    $badge = DataDefinition::create('string')->setLabel('Badge');
    DefinitionMetadata::setDefaultValue($badge, 'flame');
    $map = MapDataDefinition::create();
    $map->setLabel('Extras');
    $map->setPropertyDefinition('badge', $badge);
    $map->setPropertyDefinition('weight', DataDefinition::create('integer')->setLabel('Weight'));
    return $map;
  }

}
