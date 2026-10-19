<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Matrix;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\TypeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\VariantMismatchException;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Storage\SpecimenStorage;
use Drupal\Tests\data_surface\Kernel\DataSurfaceKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * One coercion table for every door: the form's and the tool's agree.
 *
 * Modelled on the Tool API's InputCoercionMatrixTest, over the specimen
 * (a key of every scalar type, a list and a map). Each accepted row sends
 * a value in a shape a person or a caller plausibly sends it and asserts
 * the value the key ends up holding, at two doors: the form, whose
 * extraction hands what was posted to accept() (and which mirrors the
 * Tool API's table, docs/semantics.md#the-coercion-table), and the
 * derived tool, which runs the Tool API's own InputTypeCoercionSubscriber
 * first and then the same pipeline, and whose result is read back from
 * storage. A rejected row is refused at both. The fixed-point pass feeds
 * every expected value back in and asserts it is unchanged: coercion is
 * idempotent, which is what lets the tool door coerce twice.
 *
 * Where the doors do not agree, a named test says so.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class InputCoercionMatrixTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The specimen's derived tool.
   */
  protected const TOOL = 'data_surface:tool_test.specimen:configure';

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
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the specimen in its operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The specimen.
   */
  protected function specimen(): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->buildOperation('tool_test.specimen', 'configure', []);
  }

  /**
   * Sends values through the form door.
   *
   * @param array $input
   *   What is posted, by key.
   * @param array $stored
   *   What storage holds.
   *
   * @return array|null
   *   The accepted values, or NULL when they are refused.
   */
  protected function form(array $input, array $stored): ?array {
    $surface = $this->specimen();
    $form = $this->formBuilder()->buildSurfaceForm($surface, array_replace($surface->getDefaultValues(), $stored), new FormState());
    $form_state = new FormState();
    $form_state->setValues($input);
    try {
      $values = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state, $stored);
    }
    catch (UnknownKeysException | TypeMismatchException | VariantMismatchException) {
      return NULL;
    }
    return $this->pipeline()->validate($surface, $values, $stored)->isEmpty() ? $values : NULL;
  }

  /**
   * Sends values through the tool door.
   *
   * @param array $input
   *   The tool's `values`.
   * @param array $stored
   *   What storage holds.
   *
   * @return array|null
   *   What storage holds after the call, or NULL when it was refused.
   */
  protected function tool(array $input, array $stored): ?array {
    $state = $this->container->get('state');
    $state->set(SpecimenStorage::KEY, $stored);
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL);
    try {
      $tool->setInputValue(OperationInputs::VALUES, $input);
    }
    catch (\Exception) {
      return NULL;
    }
    $tool->execute();
    return $tool->getResult()->isSuccess() ? $state->get(SpecimenStorage::KEY) : NULL;
  }

  /**
   * Values in the shapes they arrive in, and the value each must hold.
   *
   * @return \Generator
   *   Rows of the key, the value sent, the value it holds, and what is
   *   stored before.
   */
  public static function acceptedProvider(): \Generator {
    // Integers: numeric strings, integral floats and booleans.
    yield 'integer from a numeric string' => ['count', '50', 50];
    yield 'integer from a padded numeric string' => ['count', ' 7 ', 7];
    yield 'integer from a signed numeric string' => ['count', '+7', 7];
    yield 'integer from leading zeros' => ['count', '007', 7];
    yield 'integer from a float-formatted string' => ['count', '2.0', 2];
    yield 'integer from exponent notation' => ['count', '1e3', 1000];
    yield 'integer from an integral float' => ['count', 2.0, 2];
    yield 'integer from TRUE' => ['count', TRUE, 1];
    yield 'integer passes through' => ['count', 7, 7];

    // Floats: numeric strings; an integer is a float's value as it is.
    yield 'float from a numeric string' => ['ratio', '12.5', 12.5];
    yield 'float from an integer-formatted string' => ['ratio', '2', 2.0];
    yield 'float from a padded numeric string' => ['ratio', ' 1.5 ', 1.5];
    yield 'float from exponent notation' => ['ratio', '1e3', 1000.0];
    yield 'float passes through' => ['ratio', 2.5, 2.5];
    yield 'float keeps an integer' => ['ratio', 2, 2];

    // Booleans: every spelling filter_var() reads, and 0/1, 0.0/1.0.
    yield 'boolean from true' => ['flag', 'true', TRUE];
    yield 'boolean from false' => ['flag', 'false', FALSE];
    yield 'boolean from upper case' => ['flag', 'TRUE', TRUE];
    yield 'boolean from yes' => ['flag', 'yes', TRUE];
    yield 'boolean from no' => ['flag', 'no', FALSE];
    yield 'boolean from on' => ['flag', 'on', TRUE];
    yield 'boolean from off, padded' => ['flag', ' OFF ', FALSE];
    yield 'boolean from the string one' => ['flag', '1', TRUE];
    yield 'boolean from the string zero' => ['flag', '0', FALSE];
    yield 'boolean from the integer one' => ['flag', 1, TRUE];
    yield 'boolean from the integer zero' => ['flag', 0, FALSE];
    yield 'boolean from the float one' => ['flag', 1.0, TRUE];
    yield 'boolean from the float zero' => ['flag', 0.0, FALSE];
    yield 'boolean passes through' => ['flag', TRUE, TRUE];

    // Strings: a bare number is its spelling.
    yield 'string from an integer' => ['word', 5, '5'];
    yield 'string from a float' => ['word', 2.5, '2.5'];
    yield 'string from a small float, fixed-point' => ['word', 0.0000001, '0.0000001'];
    yield 'string from a float with an integer part' => ['word', 1234.567, '1234.567'];
    yield 'string passes through' => ['word', 'x', 'x'];

    // A near miss snaps onto the one choice it matches.
    yield 'choice from case and whitespace' => ['kind', ' Alpha ', 'alpha'];
    yield 'choice from upper case' => ['kind', 'BETA', 'beta'];
    yield 'choice passes through' => ['kind', 'alpha', 'alpha'];

    // Lists: a JSON list is read, a single item wrapped.
    yield 'list from a bare item' => ['tags', 'x', ['x']];
    yield 'list from a JSON list' => ['tags', '["a","b"]', ['a', 'b']];
    yield 'list passes through' => ['tags', ['a', 'b'], ['a', 'b']];
    yield 'an empty list is a list' => ['tags', [], [], ['tags' => ['a']]];

    // Maps: members coerced in place.
    yield 'map members coerced in place' => ['size', ['width' => '3', 'unit' => ' CM '], ['width' => 3, 'unit' => 'cm']];

    // Null, or the empty string a form sends for nothing, clears an
    // optional key, at both doors; a list keeps what it holds.
    yield 'null clears a stored string' => ['word', NULL, NULL, ['word' => 'kept']];
    yield 'the empty string clears a stored string' => ['word', '', NULL, ['word' => 'kept']];
    yield 'null clears a stored integer' => ['count', NULL, NULL, ['count' => 9]];
    yield 'the empty string clears a stored integer' => ['count', '', NULL, ['count' => 9]];
    yield 'null clears a stored FALSE' => ['flag', NULL, NULL, ['flag' => FALSE]];
    yield 'null clears a stored choice' => ['kind', NULL, NULL, ['kind' => 'beta']];
    yield 'null keeps a stored list' => ['tags', NULL, ['a'], ['tags' => ['a']]];
  }

  /**
   * Tests that a value arrives in the key's type, the same at both doors.
   */
  #[DataProvider('acceptedProvider')]
  public function testBothDoorsCoerceAlike(string $key, mixed $given, mixed $expected, array $stored = []): void {
    $stored += ['name' => 'Specimen'];
    $form = $this->form(['name' => 'Specimen', $key => $given], $stored);
    $this->assertNotNull($form, 'The form door accepts it.');
    $this->assertSame($expected, $form[$key], 'The form door.');
    $tool = $this->tool(['name' => 'Specimen', $key => $given], $stored);
    $this->assertNotNull($tool, 'The tool door accepts it.');
    $this->assertSame($expected, $tool[$key], 'The tool door.');
  }

  /**
   * Tests that a coerced value is a fixed point at both doors.
   */
  #[DataProvider('acceptedProvider')]
  public function testCoercedValuesAreFixedPoints(string $key, mixed $given, mixed $expected, array $stored = []): void {
    $stored += ['name' => 'Specimen'];
    $this->assertSame($expected, $this->form(['name' => 'Specimen', $key => $expected], $stored)[$key] ?? NULL, 'The form door.');
    $this->assertSame($expected, $this->tool(['name' => 'Specimen', $key => $expected], $stored)[$key] ?? NULL, 'The tool door.');
  }

  /**
   * Values whose intent is doubtful or wrong: refused, never guessed at.
   *
   * @return \Generator
   *   Rows of the key and the value sent.
   */
  public static function rejectedProvider(): \Generator {
    yield 'a fractional float for an integer' => ['count', 7.5];
    yield 'a word for an integer' => ['count', 'seven'];
    yield 'an integer below the range' => ['count', 0];
    yield 'an array for an integer' => ['count', ['5']];
    yield 'an integer beyond one for a boolean' => ['flag', 2];
    yield 'a word for a boolean' => ['flag', 'enabled'];
    yield 'a value not offered' => ['kind', 'delta'];
    yield 'not the pattern' => ['code', 'ABC1'];
    yield 'the pattern and a trailing newline' => ['code', "ab12\n"];
    yield 'a unit not offered inside the map' => ['size', ['unit' => 'mm']];
    yield 'more tags than the Count' => ['tags', ['a', 'b', 'c', 'd']];
  }

  /**
   * Tests that a doubtful or wrong value is refused at both doors.
   */
  #[DataProvider('rejectedProvider')]
  public function testBothDoorsRefuse(string $key, mixed $given): void {
    $this->assertNull($this->form(['name' => 'Specimen', $key => $given], ['name' => 'Specimen']), 'The form door refuses it.');
    $this->assertNull($this->tool(['name' => 'Specimen', $key => $given], ['name' => 'Specimen']), 'The tool door refuses it.');
  }

  /**
   * Tests the one row where the Tool API's code leaves its own principle.
   *
   * "Coerce only when the intended value is unambiguous": a numeric
   * string with a fraction for an integer is not, and the form door
   * leaves it for validation, which refuses it. The Tool API's
   * subscriber casts any numeric string to an integer, truncating '1.9'
   * to 1, before the pipeline sees it, so the tool door stores 1.
   */
  public function testFractionalStringForAnIntegerDivergesAtTheToolDoor(): void {
    $this->assertNull($this->form(['name' => 'Specimen', 'count' => '1.9'], ['name' => 'Specimen']));
    $this->assertSame(1, $this->tool(['name' => 'Specimen', 'count' => '1.9'], ['name' => 'Specimen'])['count'] ?? NULL);
  }

  /**
   * Tests values the table leaves in another type, and core lets through.
   *
   * The table leaves a boolean for a string or a float, and a boolean
   * spelling filter_var() does not read, as they arrived, for validation
   * to judge. Core's PrimitiveType check is looser than that, so they
   * are stored as sent, at both doors alike. With the Tool API's table a
   * boolean for an integer is no longer one of them: it is 0 or 1.
   */
  public function testValuesTheTableLeavesAndCoreLetsThrough(): void {
    foreach ([
      ['word', TRUE],
      ['ratio', TRUE],
      ['flag', '01'],
    ] as [$key, $given]) {
      $label = $key . ' = ' . var_export($given, TRUE);
      $this->assertSame($given, $this->form(['name' => 'Specimen', $key => $given], ['name' => 'Specimen'])[$key] ?? NULL, $label . ': the form door.');
      $this->assertSame($given, $this->tool(['name' => 'Specimen', $key => $given], ['name' => 'Specimen'])[$key] ?? NULL, $label . ': the tool door.');
    }
  }

  /**
   * Tests the datetime and timestamp rows, which no form draws.
   *
   * There is no widget for either type, so the form door is not open to
   * them; every other caller reaches accept() as the form does, and gets
   * the Tool API's answer.
   */
  public function testDatetimeAndTimestampRows(): void {
    $builder = new DataSurfaceBuilder();
    $builder->setDefinition('when', DataDefinition::create('datetime_iso8601')->setLabel('When'));
    $builder->setDefinition('day', DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly', []));
    $builder->setDefinition('moment', DataDefinition::create('datetime_iso8601')->setLabel('Moment')->addConstraint('DateTime', []));
    $builder->setDefinition('stamp', DataDefinition::create('timestamp')->setLabel('Stamp'));
    $surface = $builder->seal();
    foreach ([
      ['when', '2026-08-10 14:30:00 +0200', '2026-08-10T14:30:00+02:00'],
      ['when', '2026-08-10T14:30:00+02:00', '2026-08-10T14:30:00+02:00'],
      ['when', '2026-08-10', '2026-08-10'],
      ['when', '2026', '2026'],
      ['day', '2026-11-14', '2026-11-14'],
      ['moment', '2026-11-14T09:32:00-07:00', '2026-11-14T16:32:00'],
      ['moment', '2026-11-14T09:32:00.500Z', '2026-11-14T09:32:00'],
      ['moment', '2026-11-14 09:32:00', '2026-11-14 09:32:00'],
      ['moment', 'tomorrow', 'tomorrow'],
      ['stamp', '1700000000', 1700000000],
      ['stamp', 1700000000.0, 1700000000],
      ['stamp', '2023-11-14T22:13:20+00:00', 1700000000],
    ] as [$key, $given, $expected]) {
      $this->assertSame($expected, $this->pipeline()->accept($surface, [$key => $given])[$key], $key . ' = ' . var_export($given, TRUE));
    }
  }

  /**
   * Tests a JSON string, and null, for a map, which no form posts.
   *
   * A form draws a map as its members, so only a payload or a tool sends
   * the map as one JSON string: read, and its members coerced, by the
   * payload door (accept(), as the form's extraction calls it) and the
   * tool door alike; malformed JSON is refused by both.
   */
  public function testJsonStringForMapAtPayloadAndToolDoors(): void {
    $stored = ['name' => 'Specimen'];
    $json = '{"width":"3","unit":"cm"}';
    $this->assertSame(['width' => 3, 'unit' => 'cm'], $this->pipeline()->accept($this->specimen(), ['size' => $json], $stored)['size']);
    $this->assertSame(['width' => 3, 'unit' => 'cm'], $this->tool(['name' => 'Specimen', 'size' => $json], $stored)['size'] ?? NULL);
    try {
      $this->pipeline()->accept($this->specimen(), ['size' => '{"width": tru'], $stored);
      $this->fail('Malformed JSON is taken for a map.');
    }
    catch (TypeMismatchException $e) {
      $this->assertSame('size', $e->getPath());
    }
    $this->assertNull($this->tool(['name' => 'Specimen', 'size' => '{"width": tru'], $stored));

    // A form posts a map as its members; null for the whole map is a
    // payload's, and clears it, at both doors.
    $stored['size'] = ['width' => 4, 'unit' => 'in'];
    $this->assertNull($this->pipeline()->accept($this->specimen(), ['size' => NULL], $stored)['size']);
    $this->assertArrayHasKey('size', $this->tool(['name' => 'Specimen', 'size' => NULL], $stored) ?? []);
    $this->assertNull($this->tool(['name' => 'Specimen', 'size' => NULL], $stored)['size']);
  }

  /**
   * Tests that a key left out keeps what it holds.
   *
   * A form posts every element it drew, so leaving a key out is a
   * payload's and a tool's: what is stored stands, or the declared
   * default when nothing is.
   */
  public function testAbsentKeyKeepsWhatItHolds(): void {
    $stored = ['name' => 'Specimen', 'word' => 'kept', 'count' => 9];
    $accepted = $this->pipeline()->accept($this->specimen(), ['name' => 'Specimen'], $stored);
    $this->assertSame('kept', $accepted['word']);
    $this->assertSame(9, $accepted['count']);
    $this->assertSame(5, $this->pipeline()->accept($this->specimen(), ['name' => 'Specimen'], ['name' => 'Specimen'])['count']);
    $held = $this->tool(['name' => 'Specimen'], $stored);
    $this->assertSame('kept', $held['word'] ?? NULL);
    $this->assertSame(9, $held['count'] ?? NULL);
  }

}
