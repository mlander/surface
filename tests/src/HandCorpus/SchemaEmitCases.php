<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Port;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\DeclaredRefiner;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * Data surfaces the emitter writes from schemas alone, built by hand.
 *
 * Each case holds a key the document is written of where a definition
 * and a schema could part, and the values it is emitted over:
 * - `classRule`: a key of a class no schema derives a definition of
 *   (NotAMapDefinition, so its entry stays definition-first), at the top
 *   and inside an attached child that refines it by a sibling, refined
 *   again;
 * - `twoLevelPort`: a port read through two levels, the top's key wired
 *   to a child's port, which wires it on to its own child's port;
 * - `shapes`: a list whose item is declared with a shape, a shape inside
 *   a shape, and a collection whose item's keys are both;
 * - `rules`: declared rules: one that narrows a key, one that rules a key
 *   out, one that narrows a collection;
 * - `sameAnswer`: a key a refiner hands back an equal copy of, a port
 *   copy of an attached key, and a key inside a refined shell: where the
 *   holder's schema is the one a conditional is diffed from;
 * - `describedList`: a collection with a title, a description and a
 *   check, whose own keywords are its schema's without its items.
 *
 * Decision: see docs/decisions.md#the-document-is-written-from-schemas.
 */
final class SchemaEmitCases {

  /**
   * The cases, each a surface and the value sets it is emitted over.
   *
   * @return array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array<string, array>}>
   *   By case name.
   */
  public static function build(): array {
    return [
      'classRule' => [static::classRule(), [
        'narrow' => ['att' => ['mode' => 'narrow']],
        'same' => ['att' => ['mode' => 'same']],
        'out' => ['att' => ['mode' => 'out']],
        'none' => [],
      ],
      ],
      'twoLevelPort' => [static::twoLevelPort(), [
        's' => ['cap' => 's', 'mid' => ['deep' => ['size' => 'abcdef']]],
        'm' => ['cap' => 'm', 'mid' => ['deep' => ['size' => 'abcdef']]],
        'none' => [],
      ],
      ],
      'shapes' => [static::shapes(), [
        'held' => [
          'codes' => ['ABC'],
          'pair' => ['code' => 'XYZ'],
          'rows' => [['code' => 'ABC', 'pair' => ['code' => 'DEF', 'note' => 'n']]],
        ],
        'none' => [],
      ],
      ],
      'rules' => [static::rules(), [
        'a' => ['gate' => 'a', 'name' => 'abcdefgh', 'gone' => 'x', 'list' => [['x' => '1'], ['x' => '2']]],
        'b' => ['gate' => 'b', 'gone' => 'x'],
        'c' => ['gate' => 'c', 'list' => [['x' => '1'], ['x' => '2']]],
        'none' => [],
      ],
      ],
      'sameAnswer' => [static::sameAnswer(), [
        'copy' => ['gate' => 'copy', 'code' => 'abc', 'att' => ['mode' => 'copy', 'code' => 'abc']],
        'narrow' => ['gate' => 'narrow', 'code' => 'abc', 'att' => ['mode' => 'narrow', 'code' => 'abc']],
        'none' => [],
      ],
      ],
      'describedList' => [static::describedList(), [
        'held' => ['items' => [['x' => '1']]],
        'none' => [],
      ],
      ],
    ];
  }

  /**
   * Emits every document of every case, fresh and woken.
   *
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter.
   *
   * @return array<string, array|string>
   *   Each document, or the exception it threw, by `<case> <values>
   *   <emit>` and, but for the class rule's, `<case> woken <values>
   *   <emit>`: emit() over the values
   *   with and without widget hints, and with them over the surface
   *   refined for the values already; emitFull() with and without them.
   */
  public static function documents(ContractEmitter $emitter): array {
    $documents = [];
    foreach (static::build() as $case => [$surface, $sets]) {
      // A NotAMapDefinition wakes with no properties: core's
      // ComplexDataDefinitionBase drops them when it is serialized, and
      // only a MapDataDefinition's are kept (SerializedMaps). The class
      // rule's case is emitted fresh alone.
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $forms = $case === 'classRule' ? ['' => $surface] : ['' => $surface, ' woken' => unserialize(serialize($surface))];
      foreach ($forms as $form => $each) {
        foreach ($sets as $set => $values) {
          $documents["$case$form $set emit"] = static::attempt(static fn (): array => $emitter->emit($each, $values, 'hand', 'op')->document);
          $documents["$case$form $set emit hints"] = static::attempt(static fn (): array => $emitter->emit($each, $values, 'hand', 'op', NULL, TRUE)->document);
          // A surface refined already, which the emitter refines again.
          $documents["$case$form $set refined emit hints"] = static::attempt(static fn (): array => $emitter->emit($each->refine($values), $values, 'hand', 'op', NULL, TRUE)->document);
        }
        $documents["$case$form emitFull"] = static::attempt(static fn (): array => $emitter->emitFull($each, 'hand', 'op', 'Op')->document);
        $documents["$case$form emitFull hints"] = static::attempt(static fn (): array => $emitter->emitFull($each, 'hand', 'op', 'Op', TRUE)->document);
      }
    }
    return $documents;
  }

  /**
   * Runs one emit, giving what it threw as text.
   *
   * @param \Closure(): array $emit
   *   The emit.
   *
   * @return array|string
   *   The document, or the exception's class and message.
   */
  protected static function attempt(\Closure $emit): array|string {
    try {
      return $emit();
    }
    catch (\Throwable $e) {
      return get_class($e) . ': ' . $e->getMessage();
    }
  }

  /**
   * Builds a complex definition that is no map, of one required string.
   *
   * @return \Drupal\Tests\surface\HandCorpus\NotAMapDefinition
   *   The definition.
   */
  protected static function complex(): NotAMapDefinition {
    $complex = NotAMapDefinition::create()->setLabel('Complex');
    $complex->setPropertyDefinition('z', DataDefinition::create('string')->setLabel('Zed')->setRequired(TRUE)->addConstraint('Length', ['max' => 9]));
    return $complex;
  }

  /**
   * Builds the `classRule` case.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function classRule(): SurfaceInterface {
    $child = new SurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel('Mode')
        ->addConstraint('Choice', ['choices' => ['narrow', 'same', 'out']]),
      'cx' => static::complex(),
    ]);
    $child->addRefinement('cx', ['mode']);
    $child->addRefiner('cx', new HandRefiner([
      'narrow' => [['len', 'z', 4]],
      'same' => [['same', '']],
      'out' => [['out', 'z']],
    ], 'mode'));
    $outer = new SurfaceBuilder([
      'att' => MapDataDefinition::create()->setLabel('Att'),
      'top' => static::complex(),
    ]);
    $outer->attach('att', new Attachment($child->seal()));
    return $outer->seal();
  }

  /**
   * Builds the `twoLevelPort` case.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function twoLevelPort(): SurfaceInterface {
    $inner = new SurfaceBuilder(['size' => DataDefinition::create('string')->setLabel('Size')->addConstraint('Length', ['max' => 9])]);
    $inner->addPort(new Port('cap', 'string'));
    $inner->addRefinement('size', ['cap']);
    $inner->addRefiner('size', new HandRefiner(['s' => [['len', '', 2]], 'm' => [['len', '', 5]]], 'cap'));
    $middle = new SurfaceBuilder([
      'deep' => MapDataDefinition::create()->setLabel('Deep'),
      'own' => DataDefinition::create('string')->setLabel('Own'),
    ]);
    $middle->addPort(new Port('cap', 'string'));
    $middle->attach('deep', (new Attachment($inner->seal()))->withReads(['cap' => 'cap'], ['cap' => DataDefinition::create('string')->setLabel('Middle cap')]));
    $top = new SurfaceBuilder([
      'cap' => DataDefinition::create('string')->setLabel('Cap')->addConstraint('Choice', ['choices' => ['s', 'm']]),
      'mid' => MapDataDefinition::create()->setLabel('Mid'),
    ]);
    $top->attach('mid', (new Attachment($middle->seal()))->withReads(['cap' => 'cap'], ['cap' => DataDefinition::create('string')->setLabel('Cap')]));
    return $top->seal();
  }

  /**
   * Builds the `shapes` case.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function shapes(): SurfaceInterface {
    $code = static fn (): DataDefinition => DataDefinition::create('string')->setLabel('Code')->addConstraint('Regex', ['pattern' => '/^[A-Z]{3}$/']);
    $shaped = $code();
    DefinitionMetadata::setShape($shaped, 'test.code', $code());
    $pair = static fn (): MapDataDefinition => MapDataDefinition::create()->setLabel('Pair')
      ->setPropertyDefinition('code', clone $shaped)
      ->setPropertyDefinition('note', DataDefinition::create('string')->setLabel('Note'));
    $outer = $pair();
    DefinitionMetadata::setShape($outer, 'test.pair', $pair());
    $row = (new SurfaceBuilder(['code' => clone $shaped, 'pair' => clone $outer]))->seal();
    $builder = new SurfaceBuilder([
      'codes' => new ListDataDefinition(['label' => 'Codes'], clone $shaped),
      'pair' => clone $outer,
      'rows' => (new ListDataDefinition([], MapDataDefinition::create()))->setLabel('Rows'),
    ]);
    $builder->attachList('rows', new Collection(new Attachment($row, 'row', 'test.row')));
    return $builder->seal();
  }

  /**
   * Builds the `rules` case.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function rules(): SurfaceInterface {
    $builder = new SurfaceBuilder([
      'gate' => DataDefinition::create('string')->setLabel('Gate')
        ->addConstraint('Choice', ['choices' => ['a', 'b', 'c']]),
      'name' => DataDefinition::create('string')->setLabel('Name')->addConstraint('Length', ['max' => 20]),
      'gone' => DataDefinition::create('string')->setLabel('Gone')->setRequired(TRUE),
      'list' => (new ListDataDefinition([], MapDataDefinition::create()))->setLabel('List'),
    ]);
    $builder->attachList('list', new Collection(new Attachment((new SurfaceBuilder(['x' => DataDefinition::create('string')->setLabel('X')]))->seal())));
    $name = (new Rule('name'))->when(Condition::equals('gate', 'a'))->constrain(Schema::any()->check('drupal:Length', ['max' => 5]))->because('Short under a.');
    $gone = (new Rule('gone'))->when(Condition::equals('gate', 'b'))->doesNotApply();
    $list = (new Rule('list'))->when(Condition::equals('gate', 'c'))->constrain(Schema::any()->check('drupal:Count', ['max' => 1]));
    $rules = ['name' => [$name], 'gone' => [$gone], 'list' => [$list]];
    $link = new DeclaredRefiner($rules);
    foreach (array_keys($rules) as $key) {
      $builder->addRefinement($key, ['gate'], FALSE, TRUE);
      $builder->addRefiner($key, $link);
    }
    return $builder->seal();
  }

  /**
   * Builds the `sameAnswer` case.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function sameAnswer(): SurfaceInterface {
    $child = new SurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel('Mode')
        ->addConstraint('Choice', ['choices' => ['copy', 'narrow']]),
      'code' => DataDefinition::create('string')->setLabel('Code')->addConstraint('Length', ['max' => 9]),
    ]);
    $child->addPort(new Port('outer', 'string'));
    $child->addRefinement('code', ['mode']);
    $child->addRefiner('code', new HandRefiner(['copy' => [['clone', '']], 'narrow' => [['len', '', 4]]], 'mode'));
    $builder = new SurfaceBuilder([
      'gate' => DataDefinition::create('string')->setLabel('Gate')
        ->addConstraint('Choice', ['choices' => ['copy', 'narrow']]),
      'code' => DataDefinition::create('string')->setLabel('Code')->addConstraint('Length', ['max' => 9]),
      'att' => MapDataDefinition::create()->setLabel('Att'),
    ]);
    $builder->addRefinement('code', ['gate']);
    $builder->addRefiner('code', new HandRefiner(['copy' => [['clone', '']], 'narrow' => [['len', '', 4]]], 'gate'));
    $gate = DataDefinition::create('string')->setLabel('Gate');
    $builder->attach('att', (new Attachment($child->seal()))->withReads(['outer' => 'gate'], ['outer' => $gate]));
    return $builder->seal();
  }

  /**
   * Builds the `describedList` case: a collection with a description.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   */
  public static function describedList(): SurfaceInterface {
    $item = (new SurfaceBuilder(['x' => DataDefinition::create('string')->setLabel('X')]))->seal();
    $list = (new ListDataDefinition([], MapDataDefinition::create()))->setLabel('Items')->setDescription('One map per item.');
    $builder = new SurfaceBuilder(['items' => $list->addConstraint('Count', ['max' => 3])]);
    $builder->attachList('items', new Collection(new Attachment($item)));
    return $builder->seal();
  }

}
