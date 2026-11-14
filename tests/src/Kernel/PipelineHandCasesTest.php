<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Compute;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\Values\ValueRecorder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins what the value pipeline does with shapes no example surface has.
 *
 * The value baseline walks the examples and one test module, and none of
 * them holds these: a map default that lies over the defaults of a map
 * below it, a key labeled with the empty string in the locked and the
 * computed note, a label that is no text, a locked secret sent what it
 * holds, a stored value inside a map that differs from the one sent only
 * by spelling or type, a secret inside a map held as another type, a
 * list of lists, a datetime inside a plain list and a plain map, and a
 * secret two maps down. Each case is a data surface built by hand, one
 * input and what storage held; what the pipeline accepts, keeps, notes,
 * hands the validator and sends out is compared with the committed file
 * (tests/fixtures/pipeline-hand-cases.json, written again with
 * SURFACE_HAND_CASES_WRITE=1).
 *
 * Each case was first shown to fail under a planted fault that no other
 * test saw: a default merged one level deep, a label of '' read as none,
 * a property walked with its parent's definition, a secret read from the
 * wrong place, a list item walked with the list's node, a map's
 * properties walked one level only.
 *
 * Decision: see docs/decisions.md#the-value-pipeline-reads-nodes.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PipelineHandCasesTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * The committed records.
   */
  protected const FILE = __DIR__ . '/../../fixtures/pipeline-hand-cases.json';

  /**
   * What a record keeps of the recorder's answer.
   */
  protected const KEPT = ['accept', 'violations', 'notes', 'unmatched', 'kept', 'document', 'outbound'];

  /**
   * Tests each hand case answers as the committed file says.
   */
  public function testEachCaseIsAsCommitted(): void {
    $recorder = new ValueRecorder($this->container->get('surface.pipeline'));
    $records = [];
    foreach (static::cases() as $name => [$surface, $input, $current]) {
      $record = array_intersect_key($recorder->record($surface, $input, $current), array_flip(self::KEPT));
      $records[$name] = array_map(static fn (mixed $part): mixed => ValueRecorder::flat($part), $record);
    }
    $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (getenv('SURFACE_HAND_CASES_WRITE')) {
      file_put_contents(self::FILE, $json);
    }
    $this->assertCount(11, $records);
    $this->assertSame(file_get_contents(self::FILE), $json, 'What the pipeline does with each hand case; written again with SURFACE_HAND_CASES_WRITE=1.');
  }

  /**
   * Builds the hand cases.
   *
   * @return array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array, 2: array}>
   *   By name: the data surface, the input and what storage held.
   */
  protected static function cases(): array {
    $cases = [];

    // A map's own default over the defaults of a map below it: the merge
    // goes to every depth.
    $q = MapDataDefinition::create()->setLabel('Q')
      ->setPropertyDefinition('r', static::withDefault(DataDefinition::create('string')->setLabel('R'), 'r of q'))
      ->setPropertyDefinition('s', static::withDefault(DataDefinition::create('string')->setLabel('S'), 's of q'));
    $p = static::withDefault(MapDataDefinition::create()->setLabel('P')->setPropertyDefinition('q', $q), ['q' => ['r' => 'own']]);
    $outer = MapDataDefinition::create()->setLabel('Outer')
      ->setPropertyDefinition('p', $p)
      ->setPropertyDefinition('x', DataDefinition::create('string')->setLabel('X'));
    $cases['a default over a nested default'] = [
      static::surface(['m' => $outer]),
      ['m' => ['x' => 'sent']],
      ['m' => ['x' => 'old']],
    ];

    // A label of '' is a label: the notes say it, never the key's name.
    $computed = DataDefinition::create('string')->setLabel('');
    DefinitionMetadata::setComputed($computed, new Computed(['src'], MachineName::class, Compute::ON_CHANGE));
    $cases['a computed key and its source labeled with the empty string'] = [
      static::surface(['src' => DataDefinition::create('string')->setLabel(''), 'comp' => $computed]),
      ['src' => 'Some Title', 'comp' => 'some_title'],
      [],
    ];
    $cases['a locked key labeled with the empty string'] = [
      (new SurfaceBuilder())->setDefinition('k', DataDefinition::create('string')->setLabel(''))->lock('k', 'Fixed.')->seal(),
      ['k' => 'sent'],
      ['k' => 'held'],
    ];
    $cases['a locked key whose label is a number'] = [
      (new SurfaceBuilder())->setDefinition('k', new DataDefinition(['type' => 'string', 'label' => 5]))->lock('k', 'Fixed.')->seal(),
      ['k' => 'sent'],
      ['k' => 'held'],
    ];

    // A locked secret sent the value it holds: not taken, and said so.
    $cases['a locked secret sent what it holds'] = [
      (new SurfaceBuilder())->setDefinition('pin', static::secret(DataDefinition::create('string')->setLabel('PIN')))->lock('pin', 'Fixed.')->seal(),
      ['pin' => 'held'],
      ['pin' => 'held'],
    ];

    // Inside a map, what is kept is decided with the property's own
    // facts: a choice sent in its declared spelling over a stored near
    // miss, a number sent over the same number stored as text.
    $choices = ['choices' => ['article', 'page']];
    $map = MapDataDefinition::create()->setLabel('Map')
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'))
      ->setPropertyDefinition('c', DataDefinition::create('string')->setLabel('C')->addConstraint('Choice', $choices))
      ->setPropertyDefinition('n', DataDefinition::create('integer')->setLabel('N'));
    $held = static::surface(['m' => $map, 'n' => DataDefinition::create('integer')->setLabel('N')]);
    $cases['a choice in a map over a stored near miss'] = [
      $held,
      ['m' => ['c' => 'article']],
      ['m' => ['a' => 'x', 'c' => 'Article', 'n' => 1]],
    ];
    $cases['a number in a map over the same number stored as text'] = [
      $held,
      ['m' => ['n' => 5]],
      ['m' => ['a' => 'x', 'c' => 'page', 'n' => '5']],
    ];
    $with_secret = MapDataDefinition::create()->setLabel('With a secret')
      ->setPropertyDefinition('pin', static::secret(DataDefinition::create('integer')->setLabel('Pin')))
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    $cases['a secret in a map over the same secret stored as text'] = [
      static::surface(['m' => $with_secret]),
      ['m' => ['pin' => 5]],
      ['m' => ['pin' => '5', 'a' => 'x']],
    ];

    // A list of lists is walked to its leaves.
    $grid = new ListDataDefinition([], new ListDataDefinition([], DataDefinition::create('integer')->setLabel('Cell')));
    $cases['a list of lists'] = [
      static::surface(['grid' => $grid->setLabel('Grid')]),
      ['grid' => [['1', '2'], ['3']]],
      [],
    ];

    // A datetime inside a plain list and a plain map: the validator's
    // document writes each with its offset, what goes out does not.
    $when = DataDefinition::create('datetime_iso8601')->setLabel('When');
    $day = DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly');
    $times = MapDataDefinition::create()->setLabel('Times')
      ->setPropertyDefinition('when', $when)
      ->setPropertyDefinition('day', $day);
    $cases['dates and times in a list and in a map'] = [
      static::surface([
        'at' => DataDefinition::create('datetime_iso8601')->setLabel('At'),
        'dates' => (new ListDataDefinition([], DataDefinition::create('datetime_iso8601')->setLabel('Date')))->setLabel('Dates'),
        'times' => $times,
        'slots' => (new ListDataDefinition([], clone $times))->setLabel('Slots'),
      ]),
      [
        'at' => '2026-01-02T03:04:05',
        'dates' => ['2026-01-02T03:04:05', '2026-02-03 04:05:06'],
        'times' => ['when' => '2026-01-02T03:04:05', 'day' => '2026-01-02'],
        'slots' => [['when' => '2026-03-04T05:06:07', 'day' => '2026-03-04']],
      ],
      [],
    ];

    // A secret two maps down is kept when left out and never sent out.
    $inner = MapDataDefinition::create()->setLabel('Inner')
      ->setPropertyDefinition('pin', static::secret(DataDefinition::create('string')->setLabel('Pin')))
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    $middle = MapDataDefinition::create()->setLabel('Middle')->setPropertyDefinition('inner', $inner);
    $cases['a secret two maps down'] = [
      static::surface(['m' => MapDataDefinition::create()->setLabel('Outer')->setPropertyDefinition('middle', $middle)]),
      ['m' => ['middle' => ['inner' => ['a' => 'sent']]]],
      ['m' => ['middle' => ['inner' => ['pin' => 'kept', 'a' => 'held']]]],
    ];
    return $cases;
  }

  /**
   * Builds a data surface of the keys handed in.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The definitions, by key.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected static function surface(array $definitions): SurfaceInterface {
    $builder = new SurfaceBuilder();
    foreach ($definitions as $name => $definition) {
      $builder->setDefinition($name, $definition);
    }
    return $builder->seal();
  }

  /**
   * Gives a definition a default.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   * @param mixed $value
   *   The default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function withDefault(DataDefinition $definition, mixed $value): DataDefinition {
    DefinitionMetadata::setDefaultValue($definition, $value);
    return $definition;
  }

  /**
   * Marks a definition secret.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function secret(DataDefinition $definition): DataDefinition {
    DefinitionMetadata::setSecret($definition);
    return $definition;
  }

}
