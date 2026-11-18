<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\InputDefinitions;
use Drupal\surface_values_test\HandBuiltSurfaces;
use Drupal\Tests\surface\Values\ValueCorpus;
use Drupal\Tests\surface\Values\ValueRecorder;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the pipeline makes of each input what the value baseline holds.
 *
 * What is walked: every declaration of every example module WALKED names,
 * in each operation that needs no subject and is no default read, or
 * alone where it declares none (ExampleSurfacesTrait::examples()), built
 * for an administrator, and the test surfaces of surface_values_test,
 * which hold what no example reaches. For each, every value set
 * (ExampleValueSetsTrait::valueSets()) is a base, and every case
 * ValueCorpus yields from the full document over it is recorded by
 * ValueRecorder. A data surface whose operation creates, and every test
 * surface, is also recorded as a create (ValueCorpus::creates() over its
 * first value set). The records are pinned under tests/fixtures/values/,
 * one file per data surface, and written again with
 * SURFACE_VALUES_WRITE=1.
 *
 * The walk is split by fixture name into classes of one group each
 * (GROUPS), so no class runs long. A data surface PARTS names has its
 * value sets split across fixtures, which may be in different groups.
 * Each class pins its own counts and holds only its own files; a data
 * surface split across classes counts once in each.
 *
 * A record is held as one token per place (ValueRecorder::flat()). A
 * fixture holds, for each value set:
 * - `values`: the value set's own values, as tokens;
 * - `base`: the base case's record;
 * - `same as base`: by kind, the count of the cases recorded as the base
 *   case is;
 * - `cases`: by the place a case changes, one line per case of the
 *   corpus's kinds at that place, in order, null for a case recorded as
 *   the base case is.
 * The first value set's values and base case are held whole; a later
 * set's as their difference from the first's. A case is held as its
 * difference from the record of the case before it on its line, the
 * first case on a line as its difference from the base case. A case
 * whose difference from the base case is shorter is held as that, its
 * entry starting `^`. The create cases are held as the value set
 * CREATES, after the others.
 *
 * A line names each place and token by a name made from its text: the
 * shortest start, two characters at least, of its MD5 hash in base 64
 * that no other place's (or token's) hash in the file starts with. So a
 * place or a token added to a surface leaves every other line as it was.
 * `places` and `tokens` give each name's text. `3=a` is place 3 holding
 * token a; `-3` is place 3 and all below it gone.
 *
 * A comparison holds each value set's own values to the fixture first: a
 * value set whose values changed is told apart, and its cases are not
 * compared. Then every case, the secrets and the counts by kind. A
 * failure gives the count of differing cases by data surface and by
 * record, and the first cases whole. The counts of data surfaces, value
 * sets, cases and accepted cases are literals, so a walk that reached
 * nothing cannot pass.
 *
 * SURFACE_VALUES_ONLY=<text> limits a comparing run to the data surfaces
 * whose fixture name holds the text, for a quicker look at one. Such a
 * run compares no count and holds no stale file, and it always fails
 * once it has compared, so a lane cannot pass with it set. It has no
 * effect on a writing run.
 *
 * What it does not cover is listed in the decision.
 *
 * @see docs/decisions.md#values-are-pinned-before-the-model-changes-hands
 */
abstract class ValueBaselineTestBase extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The directory the fixtures are in.
   */
  public const FIXTURES = __DIR__ . '/../../fixtures/values/';

  /**
   * The flag that writes the fixtures again.
   */
  public const WRITE = 'SURFACE_VALUES_WRITE';

  /**
   * The flag that limits a comparing run to some data surfaces.
   */
  public const ONLY = 'SURFACE_VALUES_ONLY';

  /**
   * The name the create cases are held under, as a value set's are.
   */
  public const CREATES = 'a create, nothing stored';

  /**
   * The groups the walk is split into, by the start of a fixture name.
   *
   * A fixture is in the group of the longest start it begins with, and
   * in 'others' when it begins with none.
   */
  public const GROUPS = [
    'placement' => ['block.placement--'],
    'placement slots' => ['block.placement--create.slots'],
    'blocks' => ['block.'],
    'others' => [],
  ];

  /**
   * The data surfaces whose value sets are split across fixtures.
   *
   * By data surface, each further fixture's suffix and the start of the
   * names of the value sets it holds. The value sets no start names, and
   * the create cases, are held in the data surface's own fixture.
   */
  public const PARTS = [
    'block.placement--create' => ['slots' => 'settings/slots/'],
  ];

  /**
   * The group this class walks.
   */
  protected const GROUP = '';

  /**
   * What the walk reaches.
   */
  protected const COUNTS = [];

  /**
   * The most differing cases a failure prints whole.
   */
  protected const SHOWN = 5;

  /**
   * The example modules whose declarations are walked, SecretsParityTest's.
   */
  protected const WALKED = [
    'surface_examples',
    'surface_examples_address',
    'surface_examples_block',
    'surface_examples_classic',
    'surface_examples_compliance',
    'surface_examples_component_block',
    'surface_examples_entity',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_first',
    'surface_examples_keys',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_plugins',
    'surface_examples_react',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_examples_workflow',
  ];

  /**
   * The places of HandBuiltSurfaces::UNWRITTEN, each type beside its type id.
   */
  protected const STATED = [
    '/amount' => ['decimal', ['string', 'null']],
    '/anything' => ['any', []],
  ];

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
    'surface_http',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
    'address',
    'surface_examples_address',
    'surface_examples_classic',
    'surface_examples_entity',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_react',
    'surface_resource_test',
    'surface_values_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
    $this->installConfig(['field', 'surface_examples_keys', 'surface_examples_entity', 'surface_resource_test']);
  }

  /**
   * Answers the group a fixture is in.
   *
   * @param string $name
   *   The fixture's name, less `.json`.
   *
   * @return string
   *   The group, a key of GROUPS.
   */
  public static function groupOf(string $name): string {
    $found = 'others';
    $longest = 0;
    foreach (self::GROUPS as $group => $starts) {
      foreach ($starts as $start) {
        if (str_starts_with($name, $start) && strlen($start) > $longest) {
          [$found, $longest] = [$group, strlen($start)];
        }
      }
    }
    return $found;
  }

  /**
   * Answers whether this class walks a data surface.
   *
   * @param string $name
   *   The data surface's fixture name.
   *
   * @return bool
   *   TRUE when it is in this class's group.
   */
  protected function covers(string $name): bool {
    return static::groupOf($name) === static::GROUP;
  }

  /**
   * Builds every data surface this class walks.
   *
   * @return \Generator<int, array{0: string, 1: \Drupal\surface\SurfaceInterface, 2: array<string, mixed>|null, 3: bool}>
   *   The fixture's name, the data surface, the document its places are
   *   read from where no full document is written for it (NULL
   *   elsewhere), and whether it is recorded as a create.
   */
  protected function walked(): \Generator {
    $names = [];
    foreach ($this->container->get('surface.resource_registry')->getDefinitions() as $definition) {
      $names[$definition->id] = $definition->name();
    }
    $resources = $this->container->get('surface.resources');
    foreach ($this->examples(FALSE, [...self::WALKED, 'surface_values_test']) as [$id, $operation, $surface]) {
      $name = $id . ($operation === NULL ? '' : '--' . $operation);
      if (array_filter(array_keys(static::fixtures($name)), $this->covers(...)) !== []) {
        $creates = str_starts_with($id, 'values_test.') || ($operation !== NULL && $resources->operation($names[$id], $operation)->creates);
        yield [$name, $surface, NULL, $creates];
      }
    }
    $built = [
      HandBuiltSurfaces::ID => [HandBuiltSurfaces::surface(), NULL],
      HandBuiltSurfaces::UNWRITTEN_ID => [HandBuiltSurfaces::unwritten(), HandBuiltSurfaces::UNWRITTEN],
    ];
    foreach ($built as $name => [$surface, $document]) {
      if ($this->covers($name)) {
        yield [$name, $surface, $document, TRUE];
      }
    }
  }

  /**
   * Lists a data surface's fixtures.
   *
   * @param string $name
   *   The data surface's name.
   *
   * @return array<string, string|null>
   *   Each fixture's name, by itself the data surface's own, with the start
   *   of the names of the value sets it holds; NULL for the data surface's
   *   own.
   */
  protected static function fixtures(string $name): array {
    $fixtures = [$name => NULL];
    foreach (self::PARTS[$name] ?? [] as $part => $start) {
      $fixtures["$name.$part"] = $start;
    }
    return $fixtures;
  }

  /**
   * Answers which fixture of a data surface holds a value set.
   *
   * @param string $name
   *   The data surface's name.
   * @param string $set
   *   The value set's name.
   *
   * @return string
   *   The fixture's name.
   */
  protected static function fixtureOf(string $name, string $set): string {
    foreach (static::fixtures($name) as $fixture => $start) {
      if ($start !== NULL && str_starts_with($set, $start)) {
        return $fixture;
      }
    }
    return $name;
  }

  /**
   * Gets the tool door's input definitions, as the tool door gets them.
   *
   * @return \Drupal\surface\Tool\InputDefinitions
   *   The service the tool door's operation inputs are handed.
   */
  protected function toolInputs(): InputDefinitions {
    return $this->container->get('surface.input_definitions');
  }

  /**
   * Gets a data surface's full document, as its places are read from.
   *
   * @param string $name
   *   The data surface's fixture name.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, mixed>
   *   Its full document's schema.
   */
  protected function fullDocument(string $name, SurfaceInterface $surface): array {
    [$id, $operation] = array_pad(explode('--', $name, 2), 2, NULL);
    $schema = $this->container->get('surface.contract_emitter')->emitFull($surface, $id, $operation)->document['schema'];
    return json_decode(json_encode($schema, JSON_THROW_ON_ERROR), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Tests every case's record is the one the baseline holds.
   */
  public function testEachCaseIsRecordedAsPinned(): void {
    $write = (bool) getenv(self::WRITE);
    $only = $write ? '' : (string) getenv(self::ONLY);
    $recorder = new ValueRecorder($this->pipeline());
    $tool = $this->toolInputs();
    $counts = array_fill_keys(['surfaces', 'value sets', 'cases', 'accepted'], 0);
    $differ = [];
    $files = [];
    foreach ($this->walked() as [$name, $surface, $document, $creates]) {
      if ($document !== NULL) {
        $this->assertStated($surface, $document);
      }
      $full = NULL;
      foreach (array_keys(static::fixtures($name)) as $fixture) {
        if (!$this->covers($fixture) || ($only !== '' && !str_contains($fixture, $only))) {
          continue;
        }
        $counts['surfaces']++;
        $files[] = $fixture . '.json';
        $full ??= $document ?? $this->fullDocument($name, $surface);
        $recorder->forget();
        $runs = [];
        $pinned = $this->pinned($name, $fixture, $surface, $full, $document !== NULL, $creates && $fixture === $name, $recorder, $tool, $counts, $runs);
        $file = self::FIXTURES . $fixture . '.json';
        if ($write) {
          @mkdir(self::FIXTURES);
          file_put_contents($file, static::encode($pinned, static::order($runs)));
          continue;
        }
        $this->assertFileExists($file, sprintf('%s is pinned; written with %s=1.', $fixture, self::WRITE));
        $expected = static::decode((string) file_get_contents($file), static::order($runs));
        $differ += static::differences($fixture, $expected, $pinned, $runs);
      }
    }
    $mine = array_values(array_filter(array_map('basename', glob(self::FIXTURES . '*.json') ?: []), fn (string $file): bool => $this->covers(substr($file, 0, -5))));
    $stale = array_values(array_diff($mine, $files));
    if ($write) {
      foreach ($stale as $file) {
        unlink(self::FIXTURES . $file);
      }
      $this->markTestSkipped(sprintf('The value baseline of the %s group was written: %s.', static::GROUP, json_encode($counts)));
    }
    if ($only === '') {
      $this->assertSame([], $stale, 'Every fixture of this group is a data surface walked.');
    }
    $this->assertSame([], array_keys($differ), static::told($differ));
    if ($only !== '') {
      $this->fail(sprintf('The %d data surfaces whose name holds "%s" are as pinned. A run limited by %s compares no count and holds no stale file, so it never passes.', $counts['surfaces'], $only, self::ONLY));
    }
    $this->assertSame(static::COUNTS, $counts, 'Every data surface, value set and case walked, and those accepted.');
  }

  /**
   * Asserts a document stated by hand names exactly a data surface's places.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $document
   *   The document stated for it.
   */
  protected function assertStated(SurfaceInterface $surface, array $document): void {
    $types = [];
    $walk = static function (array $definitions, string $prefix) use (&$walk, &$types): void {
      foreach ($definitions as $key => $definition) {
        assert($definition instanceof DataDefinitionInterface);
        $pointer = $prefix . '/' . $key;
        $types[$pointer] = $definition->getDataType();
        if ($definition instanceof ListDataDefinitionInterface) {
          $walk(['0' => $definition->getItemDefinition()], $pointer);
        }
        elseif ($definition instanceof ComplexDataDefinitionInterface) {
          $walk($definition->getPropertyDefinitions(), $pointer);
        }
      }
    };
    $walk($surface->entries()->toArray(), '');
    $places = array_map(static fn (array $place): array => $place['types'], ValueCorpus::places($document));
    $stated = [];
    foreach ($types as $pointer => $type) {
      $stated[$pointer] = [$type, $places[$pointer] ?? NULL];
    }
    $this->assertSame(array_keys($types), array_keys($places), 'The stated document names exactly the data surface\'s places.');
    $this->assertSame(static::STATED, $stated, 'Each stated place has the type it is stated with beside its data type.');
  }

  /**
   * Records a data surface's cases, as its fixture holds them.
   *
   * @param string $name
   *   The data surface's name.
   * @param string $fixture
   *   The fixture recorded: the value sets fixtureOf() puts in it are.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $full
   *   Its full document.
   * @param bool $stated
   *   TRUE when the document is stated by hand, for a data surface no
   *   document is written for: its value set is its defaults alone, since
   *   the others are read from a written document.
   * @param bool $creates
   *   TRUE to record it as a create too.
   * @param \Drupal\Tests\surface\Values\ValueRecorder $recorder
   *   The recorder.
   * @param \Drupal\surface\Tool\InputDefinitions $tool
   *   The tool door's input definitions.
   * @param array<string, int> $counts
   *   The counts; gain this surface's.
   * @param array<string, array<string, array{input: array<string, mixed>, current: array<string, mixed>, flat: array<string, string>}>> $runs
   *   Set to each case's input, stored values and record, by value set
   *   and case name.
   *
   * @return array{cases: int, accepted: int, secrets: array<string, string>, sets: array<string, array{values: array<string, string|null>, base: array<string, string|null>, 'same as base': array<string, int>, cases: array<string, array<string, string|null>>}>}
   *   The fixture: the secrets, and each value set's own values, base
   *   record, the count by kind of the cases recorded as the base case
   *   is, and the difference of each other case.
   */
  protected function pinned(string $name, string $fixture, SurfaceInterface $surface, array $full, bool $stated, bool $creates, ValueRecorder $recorder, InputDefinitions $tool, array &$counts, array &$runs): array {
    $pinned = [
      'cases' => 0,
      'accepted' => 0,
      'secrets' => ValueRecorder::flat(ValueRecorder::secrets($surface, $tool)),
      'sets' => [],
    ];
    $sets = $stated ? ['defaults' => $surface->getDefaultValues()] : $this->valueSets($surface);
    $groups = [];
    $earlier = [];
    foreach ($sets as $set => $values) {
      if (static::fixtureOf($name, (string) $set) === $fixture) {
        $corpus = ValueCorpus::cases($full, $values, ValueCorpus::nearest($values, $earlier));
        $groups[(string) $set] = [$values, $corpus, FALSE];
      }
      $earlier[] = $values;
    }
    if ($creates) {
      $values = reset($sets);
      $groups[self::CREATES] = [$values, ValueCorpus::creates($full, $values), TRUE];
    }
    $first = NULL;
    $values0 = NULL;
    foreach ($groups as $set => [$values, $corpus, $create]) {
      $set = (string) $set;
      $counts['value sets'] += (int) !$create;
      $base = [];
      $same = [];
      $cases = [];
      foreach ($corpus as $case => ['kind' => $kind, 'input' => $input, 'current' => $current]) {
        try {
          $flat = ValueRecorder::flat($recorder->record($surface, $input, $current, $create));
        }
        catch (\JsonException $e) {
          throw new \LogicException(sprintf('%s [%s] %s: a record JSON cannot write: %s', $fixture, $set, $case, $e->getMessage()), 0, $e);
        }
        $runs[$set][$case] = ['input' => $input, 'current' => $current, 'flat' => $flat];
        $pinned['cases']++;
        $pinned['accepted'] += (int) !isset($flat['/accept/thrown']);
        if ($case === ValueCorpus::BASE) {
          $base = $flat;
          continue;
        }
        $difference = static::delta($base, $flat);
        if ($difference === []) {
          $same[$kind] = ($same[$kind] ?? 0) + 1;
          continue;
        }
        $cases[$case] = $difference;
      }
      ksort($same);
      $own = ValueRecorder::flat($values);
      $pinned['sets'][$set] = [
        'values' => $values0 === NULL ? $own : static::delta($values0, $own),
        'base' => $first === NULL ? $base : static::delta($first, $base),
        'same as base' => $same,
        'cases' => $cases,
      ];
      $first ??= $base;
      $values0 ??= $own;
    }
    $counts['cases'] += $pinned['cases'];
    $counts['accepted'] += $pinned['accepted'];
    return $pinned;
  }

  /**
   * Lists the cases whose record is not the one a fixture holds.
   *
   * @param string $name
   *   The data surface's fixture name.
   * @param array<string, mixed> $expected
   *   The fixture, decoded.
   * @param array<string, mixed> $actual
   *   What was recorded now, in the same form.
   * @param array<string, array<string, array{input: array<string, mixed>, current: array<string, mixed>, flat: array<string, string>}>> $runs
   *   Each case recorded now.
   *
   * @return array<string, array<string, mixed>>
   *   By "<surface> [<value set>] <case>": the input, the stored values,
   *   and the expected and actual record at each place they differ; for a
   *   value set whose own values differ, by "<surface> [<value set>]",
   *   those values.
   */
  protected static function differences(string $name, array $expected, array $actual, array $runs): array {
    $differ = [];
    if ($expected['secrets'] !== $actual['secrets']) {
      $differ["$name secrets"] = [
        'record' => 'secrets',
        'expected' => $expected['secrets'],
        'actual' => $actual['secrets'],
      ];
    }
    $first = NULL;
    $values0 = NULL;
    $now0 = NULL;
    $sets = array_unique([
      ...array_map('strval', array_keys($expected['sets'])),
      ...array_map('strval', array_keys($runs)),
    ]);
    foreach ($sets as $set) {
      $pinned = $expected['sets'][$set] ?? NULL;
      if ($pinned === NULL || !isset($runs[$set])) {
        $differ["$name [$set]"] = [
          'record' => 'values',
          'value set' => $pinned === NULL ? 'not pinned' : 'not walked now',
        ];
        continue;
      }
      $base = $first === NULL ? $pinned['base'] : static::applied($first, $pinned['base']);
      $first ??= $base;
      $values = $values0 === NULL ? $pinned['values'] : static::applied($values0, $pinned['values']);
      $values0 ??= $values;
      $now = $now0 === NULL ? $actual['sets'][$set]['values'] : static::applied($now0, $actual['sets'][$set]['values']);
      $now0 ??= $now;
      ksort($values);
      ksort($now);
      if ($values !== $now) {
        $places = array_keys(static::delta($values, $now) + static::delta($now, $values));
        $differ["$name [$set]"] = [
          'record' => 'values',
          'value set' => "The value set's own values changed; the cases below were not compared for this set.",
          'expected' => array_intersect_key($values, array_flip($places)),
          'actual' => array_intersect_key($now, array_flip($places)),
        ];
        continue;
      }
      if ($pinned['same as base'] !== $actual['sets'][$set]['same as base']) {
        $differ["$name [$set] same as base"] = [
          'record' => 'same as base',
          'expected' => $pinned['same as base'],
          'actual' => $actual['sets'][$set]['same as base'],
        ];
      }
      foreach ($pinned['unmatched'] as $pointer) {
        $differ["$name [$set] $pointer"] = [
          'record' => 'cases',
          'cases' => 'pinned at this place are not the cases walked now',
        ];
      }
      foreach ($runs[$set] as $case => ['input' => $input, 'current' => $current, 'flat' => $flat]) {
        $want = isset($pinned['cases'][$case]) ? static::applied($base, $pinned['cases'][$case]) : $base;
        $got = $flat;
        ksort($want);
        ksort($got);
        if ($want === $got) {
          continue;
        }
        $places = array_keys(static::delta($want, $got) + static::delta($got, $want));
        $differ["$name [$set] $case"] = [
          'input' => $input,
          'stored' => $current,
          'expected' => array_intersect_key($want, array_flip($places)),
          'actual' => array_intersect_key($got, array_flip($places)),
        ];
      }
    }
    return $differ;
  }

  /**
   * Says what differs: counts by data surface and by record, then examples.
   *
   * @param array<string, array<string, mixed>> $differ
   *   What differences() found, for every data surface.
   *
   * @return string
   *   The message.
   */
  protected static function told(array $differ): string {
    if ($differ === []) {
      return '';
    }
    $surfaces = [];
    $records = [];
    foreach ($differ as $at => $difference) {
      $surface = explode(' ', (string) $at, 2)[0];
      $surfaces[$surface] = ($surfaces[$surface] ?? 0) + 1;
      $named = isset($difference['record']) ? [$difference['record']] : [];
      if ($named === []) {
        foreach (array_keys((array) $difference['expected'] + (array) $difference['actual']) as $place) {
          // The record's own place is the list of the verbs it holds.
          $named[] = (string) $place === '' ? 'verbs recorded' : explode('/', ltrim((string) $place, '/'), 2)[0];
        }
      }
      foreach (array_unique($named) as $record) {
        $records[$record] = ($records[$record] ?? 0) + 1;
      }
    }
    arsort($surfaces);
    arsort($records);
    $list = static fn (array $counted): string => implode(', ', array_map(static fn (string|int $what, int $count): string => "$what $count", array_keys($counted), $counted));
    $cases = count(array_filter($differ, static fn (array $difference): bool => array_key_exists('input', $difference)));
    $shown = json_encode(array_slice($differ, 0, self::SHOWN, TRUE), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    return sprintf("%d cases differ from the value baseline, and %d other records (a surface's secrets, a value set's own values, counts or cases).\nBy data surface: %s.\nBy record: %s.\nThe first:\n%s", $cases, count($differ) - $cases, $list($surfaces), $list($records), $shown);
  }

  /**
   * Finds where one flat record differs from another.
   *
   * @param array<string, string|null> $from
   *   One record.
   * @param array<string, string|null> $to
   *   Another.
   *
   * @return array<string, string|null>
   *   Each place whose token differs, with the other's token; NULL where
   *   the other holds no place there nor below, named once at the
   *   highest such place.
   */
  protected static function delta(array $from, array $to): array {
    $delta = [];
    foreach ($to as $place => $token) {
      if (!array_key_exists($place, $from) || $from[$place] !== $token) {
        $delta[(string) $place] = $token;
      }
    }
    $gone = array_diff_key($from, $to);
    foreach (array_keys($gone) as $place) {
      $place = (string) $place;
      $parent = substr($place, 0, (int) strrpos($place, '/'));
      if ($place !== '' && $parent !== '' && array_key_exists($parent, $gone)) {
        continue;
      }
      $delta[$place] = NULL;
    }
    return $delta;
  }

  /**
   * Applies a difference to a flat record.
   *
   * @param array<string, string|null> $base
   *   The record.
   * @param array<string, string|null> $delta
   *   The difference.
   *
   * @return array<string, string|null>
   *   The record it makes.
   */
  protected static function applied(array $base, array $delta): array {
    foreach ($delta as $place => $token) {
      if ($token !== NULL) {
        $base[$place] = $token;
        continue;
      }
      foreach (array_keys($base) as $held) {
        if ($held === $place || str_starts_with((string) $held, $place . '/')) {
          unset($base[$held]);
        }
      }
    }
    return $base;
  }

  /**
   * Lists the kinds of the cases recorded at each place, in order.
   *
   * @param array<string, array<string, mixed>> $runs
   *   The cases recorded, by value set and name.
   *
   * @return array<string, array<string, list<string>>>
   *   By value set and place, the kinds, the base case left out.
   */
  protected static function order(array $runs): array {
    $order = [];
    foreach ($runs as $set => $cases) {
      foreach (array_keys($cases) as $case) {
        if ($case !== ValueCorpus::BASE) {
          [$pointer, $kind] = explode(' ', (string) $case, 2);
          $order[(string) $set][$pointer][] = $kind;
        }
      }
    }
    return $order;
  }

  /**
   * Names each of a file's texts by the start of its hash.
   *
   * @param list<string> $texts
   *   The texts, each once.
   *
   * @return array<string, string>
   *   Each text's name, by the text: the shortest start of its MD5 hash in
   *   base 64 ('+' and '/' written '.' and '_'), two characters at
   *   least, that no other text's hash starts with.
   */
  protected static function names(array $texts): array {
    $hashes = [];
    foreach ($texts as $text) {
      $hashes[$text] = strtr(rtrim(base64_encode(md5($text, TRUE)), '='), '+/', '._');
    }
    $sorted = array_values($hashes);
    sort($sorted, SORT_STRING);
    $length = [];
    foreach ($sorted as $i => $hash) {
      $needed = 2;
      foreach ([$sorted[$i - 1] ?? NULL, $sorted[$i + 1] ?? NULL] as $other) {
        if ($other !== NULL) {
          $common = strspn($hash ^ $other, "\0");
          $needed = max($needed, $common + 1);
        }
      }
      $length[$hash] = $needed;
    }
    return array_map(static fn (string $hash): string => substr($hash, 0, $length[$hash]), $hashes);
  }

  /**
   * Writes a fixture: each place's cases on a line, places and tokens named.
   *
   * @param array{cases: int, accepted: int, secrets: array<string, string>, sets: array<string, array{values: array<string, string|null>, base: array<string, string|null>, 'same as base': array<string, int>, cases: array<string, array<string, string|null>>}>} $pinned
   *   The fixture.
   * @param array<string, array<string, list<string>>> $order
   *   The kinds of the cases at each place, in the corpus's order, by
   *   value set (order()).
   *
   * @return string
   *   Its JSON.
   */
  protected static function encode(array $pinned, array $order): string {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    // Each set's lines as differences, before any is named.
    $sets = [];
    $first = NULL;
    foreach ($pinned['sets'] as $set => $held) {
      $base = $first === NULL ? $held['base'] : static::applied($first, $held['base']);
      $first ??= $base;
      $lines = [];
      foreach ($order[$set] ?? [] as $pointer => $kinds) {
        $previous = $base;
        $written = [];
        foreach ($kinds as $kind) {
          $difference = $held['cases'][$pointer . ' ' . $kind] ?? NULL;
          if ($difference === NULL) {
            $written[] = NULL;
            continue;
          }
          $record = static::applied($base, $difference);
          $from = static::delta($previous, $record);
          // From the base case where that is shorter, marked so.
          $written[] = $previous !== $base && count($difference) < count($from) ? ['^' => TRUE] + $difference : $from;
          $previous = $record;
        }
        if (array_filter($written, 'is_array') !== []) {
          $lines[(string) $pointer] = $written;
        }
      }
      $sets[(string) $set] = $lines;
    }
    $places = [];
    $tokens = [];
    $gather = static function (array $flat) use (&$places, &$tokens): void {
      foreach ($flat as $place => $token) {
        $places[(string) $place] = TRUE;
        if ($token !== NULL) {
          $tokens[$token] = TRUE;
        }
      }
    };
    $gather($pinned['secrets']);
    foreach ($pinned['sets'] as $set => $held) {
      $gather($held['values']);
      $gather($held['base']);
      foreach ($sets[$set] as $listed) {
        foreach (array_filter($listed, 'is_array') as $difference) {
          $gather(array_diff_key($difference, ['^' => TRUE]));
        }
      }
    }
    $place = static::names(array_map('strval', array_keys($places)));
    $token = static::names(array_map('strval', array_keys($tokens)));
    $line = static function (array $flat) use ($place, $token): string {
      $parts = [];
      foreach ($flat as $at => $held) {
        $parts[] = $held === NULL ? '-' . $place[(string) $at] : $place[(string) $at] . '=' . $token[$held];
      }
      return implode(' ', $parts);
    };
    $written = [];
    foreach ($pinned['sets'] as $set => $held) {
      $lines = [];
      foreach ($sets[$set] as $pointer => $cases) {
        $lines[] = '        ' . json_encode((string) $pointer, $flags) . ': ' . json_encode(array_map(static fn (?array $difference): ?string => $difference === NULL ? NULL : (isset($difference['^']) ? '^' . $line(array_diff_key($difference, ['^' => TRUE])) : $line($difference)), $cases), $flags);
      }
      $written[] = implode("\n", [
        '    ' . json_encode((string) $set, $flags) . ': {',
        '      "values": ' . json_encode($line($held['values']), $flags) . ',',
        '      "base": ' . json_encode($line($held['base']), $flags) . ',',
        '      "same as base": ' . json_encode($held['same as base'] === [] ? new \stdClass() : $held['same as base'], $flags) . ',',
        $lines === [] ? '      "cases": {}' : "      \"cases\": {\n" . implode(",\n", $lines) . "\n      }",
        '    }',
      ]);
    }
    ksort($place, SORT_STRING);
    $token = array_flip($token);
    ksort($token, SORT_STRING);
    $map = static fn (array $named): string => implode(",\n", array_map(static fn (string|int $name, string $text): string => '    ' . json_encode((string) $name, $flags) . ': ' . json_encode($text, $flags), array_keys($named), $named));
    return implode("\n", [
      '{',
      '  "cases": ' . $pinned['cases'] . ',',
      '  "accepted": ' . $pinned['accepted'] . ',',
      '  "secrets": ' . json_encode($line($pinned['secrets']), $flags) . ',',
      '  "sets": {',
      implode(",\n", $written),
      '  },',
      '  "places": {',
      $map(array_flip($place)),
      '  },',
      '  "tokens": {',
      $map($token),
      '  }',
      '}',
    ]) . "\n";
  }

  /**
   * Reads a fixture back into the form pinned() makes.
   *
   * @param string $json
   *   The fixture's JSON.
   * @param array<string, array<string, list<string>>> $order
   *   The kinds of the cases at each place walked now, as encode() takes
   *   them.
   *
   * @return array{cases: int, accepted: int, secrets: array<string, string|null>, sets: array<string, array{values: array<string, string|null>, base: array<string, string|null>, 'same as base': array<string, int>, cases: array<string, array<string, string|null>>, unmatched: list<string>}>}
   *   The fixture: each case by its name, and in `unmatched` each place
   *   whose pinned cases are not as many as the kinds walked now.
   */
  protected static function decode(string $json, array $order): array {
    $fixture = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $places = $fixture['places'];
    $tokens = $fixture['tokens'];
    $flat = static function (string $line) use ($places, $tokens): array {
      $read = [];
      foreach ($line === '' ? [] : explode(' ', $line) as $part) {
        if ($part[0] === '-') {
          $read[(string) $places[substr($part, 1)]] = NULL;
          continue;
        }
        [$p, $t] = explode('=', $part, 2);
        $read[(string) $places[$p]] = (string) $tokens[$t];
      }
      return $read;
    };
    $sets = [];
    $first = NULL;
    foreach ($fixture['sets'] as $set => $held) {
      $delta = $flat($held['base']);
      $base = $first === NULL ? $delta : static::applied($first, $delta);
      $first ??= $base;
      $cases = [];
      $unmatched = [];
      foreach ($held['cases'] as $pointer => $lines) {
        $kinds = $order[(string) $set][(string) $pointer] ?? [];
        if (count($kinds) !== count($lines)) {
          $unmatched[] = (string) $pointer;
          continue;
        }
        $previous = $base;
        foreach ($lines as $i => $line) {
          if ($line === NULL) {
            continue;
          }
          $previous = str_starts_with($line, '^') ? static::applied($base, $flat(substr($line, 1))) : static::applied($previous, $flat($line));
          $cases[$pointer . ' ' . $kinds[$i]] = static::delta($base, $previous);
        }
      }
      $sets[(string) $set] = [
        'values' => $flat($held['values']),
        'base' => $delta,
        'same as base' => $held['same as base'],
        'cases' => $cases,
        'unmatched' => $unmatched,
      ];
    }
    return [
      'cases' => $fixture['cases'],
      'accepted' => $fixture['accepted'],
      'secrets' => $flat($fixture['secrets']),
      'sets' => $sets,
    ];
  }

}
