<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\DefinitionMap;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Entry;
use Drupal\surface\Port;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\Tests\surface\HandCorpus\HandRefiner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a refined shell, composed of schemas, and what reads it.
 *
 * A refinement composes the schema of a key that holds a child frame (an
 * attachment, chosen variants, a structure at a mount) of the key's own
 * schema and the refined child's entries' schemas
 * (DefinitionSchema::withProperties()), schema-first; it keeps the
 * definition built as before, handed with that schema, where the schema holds
 * a class no schema derives a definition of, where a deciding key is locked,
 * and where a mount's own refiners refined it too. The narrowing check of
 * the whole shell goes where it cannot fire: each of the child's keys is
 * held to it when the child refines it, and a widening there is refused
 * with the child's own message.
 *
 * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RefinedShellTest extends SurfaceKernelTestBase {

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
  ];

  /**
   * Builds the child every shell holds: its code refined by its mode.
   *
   * @param \Drupal\surface\SurfaceRefinerInterface|null $refiner
   *   What refines the code, by the mode; a HandRefiner by default.
   * @param \Drupal\Core\TypedData\DataDefinition|null $code
   *   The code as advertised; a required string of up to eight by default.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The child, sealed.
   */
  protected static function child(?SurfaceRefinerInterface $refiner = NULL, ?DataDefinition $code = NULL): SurfaceInterface {
    $code ??= DataDefinition::create('string')->setLabel('Code')->setRequired(TRUE)->addConstraint('Length', ['max' => 8]);
    $builder = new SurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel('Mode'),
      'code' => $code,
      'note' => DataDefinition::create('string')->setLabel('Note'),
    ]);
    $builder->addRefinement('code', ['mode']);
    $builder->addRefiner('code', $refiner ?? new HandRefiner([
      'narrow' => [['len', '', 4]],
      'narrower' => [['len', '', 2]],
      'widen' => [['len', '', 20]],
      'same' => [['same', '']],
      'out' => [['out', '']],
      'optional' => [['label', '', 'Code, narrowed']],
    ], 'mode'));
    return $builder->seal();
  }

  /**
   * Builds a data surface with every kind of shell around the child.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   An attachment (`att`), variants a sibling chooses (`pick`, by
   *   `kind`) and variants whose deciding key is locked (`fixed`, by
   *   `lock`, chosen `x`), a shell inside a shell (`deep`, whose child
   *   attaches the child at `inner`), and the child mounted (`surface`).
   */
  protected static function shells(SurfaceInterface $child): SurfaceInterface {
    $other = (new SurfaceBuilder(['other' => DataDefinition::create('string')->setLabel('Other')]))->seal();
    $holder = new SurfaceBuilder([
      'title' => DataDefinition::create('string')->setLabel('Title'),
      'inner' => MapDataDefinition::create()->setLabel('Inner'),
    ]);
    $holder->attach('inner', new Attachment($child));
    $builder = new SurfaceBuilder([
      'att' => MapDataDefinition::create()->setLabel('Att')->setDescription('Attached.')->setRequired(TRUE),
      'kind' => DataDefinition::create('string')->setLabel('Kind'),
      'pick' => MapDataDefinition::create()->setLabel('Pick'),
      'lock' => DataDefinition::create('string')->setLabel('Lock'),
      'fixed' => MapDataDefinition::create()->setLabel('Fixed'),
      'deep' => MapDataDefinition::create()->setLabel('Deep'),
    ]);
    $builder->attach('att', new Attachment($child));
    $builder->attachVariants('pick', 'kind', ['x' => new Attachment($child), 'y' => new Attachment($other)]);
    $builder->attachVariants('fixed', 'lock', ['x' => new Attachment($child), 'y' => new Attachment($other)]);
    $builder->setDefault('lock', 'x');
    $builder->lock('lock');
    $builder->attach('deep', new Attachment($holder->seal()));
    $builder->mountStructure('surface', new Attachment($child));
    return $builder->seal();
  }

  /**
   * Values that set the child's mode at each shell.
   *
   * @param string $mode
   *   The mode.
   *
   * @return array
   *   The values.
   */
  protected static function at(string $mode): array {
    return [
      'att' => ['mode' => $mode],
      'kind' => 'x',
      'pick' => ['mode' => $mode],
      'lock' => 'x',
      'fixed' => ['mode' => $mode],
      'deep' => ['inner' => ['mode' => $mode]],
      'third_party_settings' => ['surface' => ['mode' => $mode]],
    ];
  }

  /**
   * Tests the composer places a child's keys in a shell's schema.
   */
  public function testTheComposer(): void {
    $shell = MapDataDefinition::create()->setLabel('Shell')->setDescription('Its own.')->setSetting('widget', 'fieldset')->setRequired(TRUE)->addConstraint('Count', ['max' => 3]);
    $shell->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    $schema = DefinitionSchema::of($shell);
    $required = DefinitionSchema::of(DataDefinition::create('string')->setLabel('B')->setRequired(TRUE));
    $optional = DefinitionSchema::of(DataDefinition::create('integer')->setLabel('C'));
    $composed = DefinitionSchema::withProperties($schema, ['b' => $required, 'c' => $optional]);
    $this->assertSame(['b', 'c'], array_keys((array) $composed->get('properties')), 'The properties given, in their order, the one the shell held gone.');
    $this->assertSame($required, DefinitionSchema::propertyOf($composed, 'b'), 'The very schema given.');
    $this->assertSame(['b'], $composed->get('required'), 'The required one in `required`, the optional one not.');
    $read = MapDataDefinition::create()->setLabel('Shell')->setDescription('Its own.')->setSetting('widget', 'fieldset')->setRequired(TRUE)->addConstraint('Count', ['max' => 3]);
    $read->setPropertyDefinition('b', DataDefinition::create('string')->setLabel('B')->setRequired(TRUE));
    $read->setPropertyDefinition('c', DataDefinition::create('integer')->setLabel('C'));
    $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($read), $composed), 'The shell\'s own facts kept: its label, description, setting, required flag and check on the map.');
    $none = DefinitionSchema::withProperties($composed, ['c' => $optional]);
    $this->assertFalse($none->has('required'), 'No required property, no `required`.');
    $first = DefinitionSchema::withProperties($schema, ['c' => $optional]);
    $placed = DefinitionSchema::withProperties($first, ['c' => $optional, 'b' => $required]);
    $back = MapDataDefinition::create()->setLabel('Shell')->setDescription('Its own.')->setSetting('widget', 'fieldset')->setRequired(TRUE)->addConstraint('Count', ['max' => 3]);
    $back->setPropertyDefinition('c', DataDefinition::create('integer')->setLabel('C'));
    $back->setPropertyDefinition('b', DataDefinition::create('string')->setLabel('B')->setRequired(TRUE));
    $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($back), $placed), '`required` placed where the read places it, where the schema held none.');
    $default = MapDataDefinition::create()->setLabel('Default');
    $default->setPropertyDefinition('a', DataDefinition::create('string'));
    DefinitionMetadata::setDefaultValue($default, ['a' => 'x']);
    $kept = DefinitionSchema::withProperties(DefinitionSchema::of($default), ['a' => DefinitionSchema::of(DataDefinition::create('string')->setLabel('A'))]);
    $this->assertSame(['a' => 'x'], SchemaFacts::defaultOf($kept), 'The shell\'s own default kept.');
    $variants = MapDataDefinition::create()->setLabel('Variants shell')->setRequired(TRUE);
    $own = DefinitionSchema::shell($variants);
    $this->assertSame([], $own->get('properties'), 'A shell of variants is read as a map that holds nothing yet.');
    $chosen = clone $variants;
    $chosen->setPropertyDefinition('b', DataDefinition::create('string')->setLabel('B')->setRequired(TRUE));
    $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($chosen), DefinitionSchema::withProperties($own, ['b' => $required])), 'And composed, it is the read of the chosen map.');
  }

  /**
   * Tests every refined shell is composed of schemas, schema-first.
   */
  public function testEachShellIsComposed(): void {
    $surface = static::shells(static::child());
    foreach (['narrow', 'same', 'optional', 'out'] as $mode) {
      $refined = $surface->refine(static::at($mode));
      foreach (['att', 'pick', 'deep', 'third_party_settings'] as $key) {
        $entry = $refined->getDefinitions()->entry($key);
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isSchemaFirst(), "$key ($mode) is schema-first.");
        $this->assertFalse($entry->sameSource($surface->getDefinitions()->entry($key)), "$key ($mode) has a source of its own, even where its child changed nothing.");
        $this->assertTrue(DefinitionSchema::same($entry->schema(), DefinitionSchema::of($entry->definition())), "$key ($mode): its schema is what its definition reads.");
      }
      $fixed = $refined->getDefinitions()->entry('fixed');
      $this->assertFalse($fixed->isSchemaFirst(), "fixed ($mode): a locked deciding key keeps its definition and its narrowing check.");
      $this->assertTrue(DefinitionSchema::same($fixed->schema(), DefinitionSchema::of($fixed->definition())));
    }
    $out = $surface->refine(static::at('out'));
    $this->assertTrue(SchemaFacts::doesNotApply(DefinitionSchema::propertyOf($out->getDefinitions()->entry('att')->schema(), 'code')), 'A key the child ruled out is marked in the shell\'s schema.');
    $this->assertSame(['inner.code'], SchemaFacts::ruledOutInside($out->getDefinitions()->entry('deep')->schema()), 'And in a shell inside a shell.');
    $this->assertSame(['surface.code'], SchemaFacts::ruledOutInside($out->getDefinitions()->entry('third_party_settings')->schema()), 'And at a mount.');
  }

  /**
   * Tests a shell holding a class no schema derives keeps a definition.
   *
   * Before refiners answered with schemas, the child's refiner handed back
   * a definition of a class no schema derives, and the shells built from
   * the refined child were held to that. A refiner's answer names no
   * class, so the class is the advertised key's here: the child's `code`
   * is of a class outside the three, and narrowing it keeps the shells
   * definition-first.
   */
  public function testTheClassRule(): void {
    $foreign = new class(['type' => 'string']) extends DataDefinition {};
    $foreign->setLabel('Code')->setRequired(TRUE)->addConstraint('Length', ['max' => 8]);
    $surface = static::shells(static::child(NULL, $foreign));
    $refined = $surface->refine(static::at('narrow'));
    foreach (['att', 'pick', 'fixed', 'deep', 'third_party_settings'] as $key) {
      $entry = $refined->getDefinitions()->entry($key);
      $this->assertNotNull($entry);
      $this->assertFalse($entry->isSchemaFirst(), "$key: definition-first.");
      $this->assertFalse(DefinitionMap::derivedClasses($entry->schema()), "$key: its schema holds a class no schema derives.");
      $this->assertTrue(DefinitionSchema::same($entry->schema(), DefinitionSchema::of($entry->definition())), "$key: its schema, composed, is what its definition, built as before, reads.");
    }
    $plain = static::shells(static::child())->refine(static::at('narrow'));
    $this->assertTrue($plain->getDefinitions()->entry('att')->isSchemaFirst(), 'The same shell is schema-first where the child\'s keys are of the derived classes.');
  }

  /**
   * Tests a widening inside a child is refused, as the child refuses it.
   */
  public function testWideningIsRefusedByTheChild(): void {
    $surface = static::shells(static::child());
    $message = 'Refining "code" for the declaring class widened what it was given: the max of the Length constraint moved from 8 to 20. Refinement may only narrow; widening is a build-time act, and the build is over.';
    $cases = [
      'attachment' => ['att' => ['mode' => 'widen']],
      'chosen variants' => ['kind' => 'x', 'pick' => ['mode' => 'widen']],
      'a locked variant' => ['lock' => 'x', 'fixed' => ['mode' => 'widen']],
      'a shell inside a shell' => ['deep' => ['inner' => ['mode' => 'widen']]],
      'a structure at a mount' => ['third_party_settings' => ['surface' => ['mode' => 'widen']]],
    ];
    foreach ($cases as $where => $values) {
      try {
        $surface->refine($values);
        $this->fail("$where: the widening was taken.");
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $where);
      }
    }
  }

  /**
   * Tests a refined surface refined again keeps the narrowing check.
   *
   * Refined once with the child's code narrowed to 4, then refined again
   * with values that give the advertised 8 back: each shell refuses, as
   * the code before refused, fresh and woken from serialize(). Its child
   * is the sealed one, so only the check of the whole shell, on the
   * definition built as before, sees the widening
   * (Entry::madeByRefinement()). Refined again with the same narrowing or
   * a narrower one, it answers.
   */
  public function testRefinedAgainKeepsTheNarrowingCheck(): void {
    $surface = static::shells(static::child());
    $first = static::at('narrow');
    $moved = 'the max of the Length constraint moved from 4 to 8.';
    $child = 'for the child attached there widened what it was given:';
    $cases = [
      'att' => [
        ['att' => ['mode' => 'none']],
        "Refining \"att\" $child inside the code property, $moved",
      ],
      'pick' => [
        ['pick' => ['mode' => 'none']],
        "Refining \"pick\" $child inside the code property, $moved",
      ],
      'deep' => [
        ['deep' => ['inner' => ['mode' => 'none']]],
        "Refining \"deep\" $child inside the inner property, inside the code property, $moved",
      ],
      'mount' => [
        ['third_party_settings' => ['surface' => ['mode' => 'none']]],
        "Refining \"third_party_settings\" for the structures attached at its mounts widened what it was given: inside the surface property, inside the code property, $moved",
      ],
    ];
    $refined = $surface->refine($first);
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($refined));
    foreach (['fresh' => $refined, 'woken' => $woken] as $how => $again) {
      foreach ($cases as $what => [$values, $message]) {
        try {
          $again->refine(array_replace($first, $values));
          $this->fail("$what ($how): the widening was taken.");
        }
        catch (\LogicException $e) {
          $this->assertStringStartsWith($message, $e->getMessage(), "$what ($how)");
        }
      }
      $same = $again->refine($first);
      $this->assertSame(4, DefinitionSchema::propertyOf($same->getDefinitions()->entry('att')->schema(), 'code')?->get('maxLength'), "The same narrowing again answers ($how).");
      $narrower = $again->refine(static::at('narrower'));
      foreach (['att', 'pick', 'deep', 'third_party_settings'] as $key) {
        $this->assertTrue($narrower->getDefinitions()->entry($key)->madeByRefinement(), "$key ($how): refined again, made by the refinement.");
      }
      $this->assertSame(2, DefinitionSchema::propertyOf($narrower->getDefinitions()->entry('att')->schema(), 'code')?->get('maxLength'), "A narrower one answers ($how).");
    }
    $this->assertFalse($surface->getDefinitions()->entry('att')->madeByRefinement(), 'A sealed entry is not made by a refinement.');
    $this->assertTrue($refined->getDefinitions()->entry('att')->madeByRefinement(), 'A composed one is.');
    $this->assertTrue($woken->getDefinitions()->entry('att')->madeByRefinement(), 'And it wakes so.');
  }

  /**
   * Tests a watched key the refiner's answer holds is walked by it.
   *
   * A key of a class no schema derives a definition of, whose definition
   * answers for a property its properties do not list: the watched value
   * there is judged by that definition, as before, not by the schema.
   */
  public function testWatchedKeyOfGivenDefinitionIsWalkedByIt(): void {
    $cfg = new class(['type' => 'map']) extends MapDataDefinition {

      /**
       * {@inheritdoc}
       */
      public function getPropertyDefinition($name) {
        return $name === 'x' ? DataDefinition::create('integer')->setLabel('X') : parent::getPropertyDefinition($name);
      }

    };
    $cfg->setPropertyDefinition('y', DataDefinition::create('string')->setLabel('Y'));
    $builder = new SurfaceBuilder([
      'cfg' => $cfg,
      'target' => DataDefinition::create('string')->setLabel('Target'),
    ]);
    $builder->addRefinement('target', ['cfg.x']);
    $builder->addRefiner('target', new HandRefiner([]));
    $builder->setWatchedValueCheck($this->container->get('surface.watched_value_check'));
    $surface = $builder->seal();
    $this->assertFalse($surface->getDefinitions()->entry('cfg')->isSchemaFirst());
    $this->assertSame(['cfg.x' => 'word'], $surface->refine(['cfg' => ['x' => 'word']])->withheldValues());
    $this->assertSame([], $surface->refine(['cfg' => ['x' => 3]])->withheldValues());
  }

  /**
   * Builds a frame whose watched key is a definition its schema does not say.
   *
   * Before refiners answered with schemas, the refiner of `cfg` handed
   * back a copy of a definition that answers for `x`, a property its
   * properties do not list, marked; an answer says only what the key's
   * schema holds, so `x` is a property `cfg` lists, and the refiner rules
   * it out by its answer.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   `cfg`, a map of a class no schema derives a definition of, holding `x`
   *   (one of a and b) and `y`, `x` ruled out once `mode` is `out`;
   *   `target` (Length 9), narrowed to 3 by `cfg.x` holding `a`.
   */
  protected function definitionWatchedFrame(): SurfaceInterface {
    $cfg = new class(['type' => 'map']) extends MapDataDefinition {};
    $cfg->setLabel('Cfg')
      ->setPropertyDefinition('x', DataDefinition::create('string')->setLabel('X')->addConstraint('Choice', [
        'choices' => ['a', 'b'],
      ]))
      ->setPropertyDefinition('y', DataDefinition::create('string')->setLabel('Y'));
    $ruling = new class implements SurfaceRefinerInterface {

      /**
       * {@inheritdoc}
       */
      public function refine(string $name, Schema $key, array $values): array {
        return ($values['mode'] ?? NULL) === 'out' ? [Schema::any()->with('properties', ['x' => FALSE])] : [];
      }

    };
    $builder = new SurfaceBuilder([
      'cfg' => $cfg,
      'mode' => DataDefinition::create('string')->setLabel('Mode'),
      'target' => DataDefinition::create('string')->setLabel('Target')->addConstraint('Length', ['max' => 9]),
    ]);
    $builder->addRefinement('cfg', ['mode']);
    $builder->addRefiner('cfg', $ruling);
    $builder->addRefinement('target', ['cfg.x']);
    $builder->addRefiner('target', new HandRefiner(['a' => [['len', '', 3]]], 'cfg.x'));
    $builder->setWatchedValueCheck($this->container->get('surface.watched_value_check'));
    return $builder->seal();
  }

  /**
   * Tests a watched place a definition rules out is withheld, as before.
   *
   * A key whose source is a definition is walked by that definition, part
   * by part, by both watched-value walks: where it rules `x` out, the
   * watcher is not handed `x`'s value and stays at its sealed bound, at
   * the top and inside an attachment's child; where it does not, the
   * watcher narrows.
   */
  public function testRuledOutPlaceOfGivenDefinitionIsWithheld(): void {
    $frame = $this->definitionWatchedFrame();
    $this->assertFalse($frame->getDefinitions()->entry('cfg')->isSchemaFirst());
    $max = static fn (SurfaceInterface $refined): mixed => $refined->getDefinitions()->entry('target')->schema()->get('maxLength');
    $this->assertSame(9, $max($frame->refine(['cfg' => ['x' => 'a'], 'mode' => 'out'])), 'Ruled out: the watcher stays at its bound.');
    $this->assertSame(3, $max($frame->refine(['cfg' => ['x' => 'a']])), 'Not ruled out: the watcher narrows.');
    $holder = new SurfaceBuilder(['att' => MapDataDefinition::create()->setLabel('Att')]);
    $holder->attach('att', new Attachment($frame));
    $surface = $holder->seal();
    $inside = static fn (array $values): mixed => DefinitionSchema::propertyOf($surface->refine(['att' => $values])->getDefinitions()->entry('att')->schema(), 'target')?->get('maxLength');
    $this->assertSame(9, $inside(['cfg' => ['x' => 'a'], 'mode' => 'out']), 'Inside an attachment\'s child, too.');
    $this->assertSame(3, $inside(['cfg' => ['x' => 'a']]));
  }

  /**
   * Tests a watched key the refinement rules out hands its watcher nothing.
   *
   * The key still holds a value; the watcher is refined as if it held
   * none, against the refined surface's mark, not the sealed one's.
   */
  public function testRuledOutWatchedKeyHandsNothing(): void {
    $builder = new SurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel('Mode'),
      'w' => DataDefinition::create('string')->setLabel('W'),
      'target' => DataDefinition::create('string')->setLabel('Target')->addConstraint('Length', ['max' => 9]),
    ]);
    $builder->addRefinement('w', ['mode']);
    $builder->addRefiner('w', new HandRefiner(['out' => [['out', '']]], 'mode'));
    $builder->addRefinement('target', ['w']);
    $builder->addRefiner('target', new HandRefiner(['v' => [['len', '', 3]]], 'w'));
    $builder->setWatchedValueCheck($this->container->get('surface.watched_value_check'));
    $surface = $builder->seal();
    $max = static fn (array $values): mixed => $surface->refine($values)->getDefinitions()->entry('target')->schema()->get('maxLength');
    $this->assertSame(9, $max(['mode' => 'out', 'w' => 'v']), 'Ruled out, still holding a value: the watcher stays at its bound.');
    $this->assertSame(3, $max(['mode' => 'in', 'w' => 'v']), 'Applying: the watcher narrows.');
  }

  /**
   * Tests a refined shell bound to a through port, refined again, refuses.
   *
   * A refined surface handed through-port values (withPorts()) copies its
   * refined shell for the ports (Entry::readingFrom()); the copy carries
   * the mark that a refinement made it, so a second refinement that gives
   * back what the first narrowed is refused, as before.
   */
  public function testThroughPortCopyRefinedAgainRefuses(): void {
    $child = new SurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel('Mode'),
      'code' => DataDefinition::create('string')->setLabel('Code')->setRequired(TRUE)->addConstraint('Length', ['max' => 8]),
    ]);
    $child->addPort(new Port('q', 'string'));
    $child->addRefinement('code', ['mode']);
    $child->addRefiner('code', new HandRefiner(['narrow' => [['len', '', 4]]], 'mode'));
    $through = Port::through('sib');
    $builder = new SurfaceBuilder(['e' => MapDataDefinition::create()->setLabel('E')]);
    $builder->addPort($through);
    $builder->attach('e', new Attachment($child->seal(), reads: ['q' => $through->name . '.x']));
    $surface = $builder->seal();
    $refined = $surface->refine(['e' => ['mode' => 'narrow']]);
    $bound = $refined->withPorts([$through->name => ['x' => 'v']]);
    $entry = $bound->getDefinitions()->entry('e');
    $this->assertNotSame($refined->getDefinitions()->entry('e'), $entry, 'The through port copies the refined shell.');
    $this->assertTrue($entry->madeByRefinement(), 'The copy carries the mark.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "e" for the child attached there widened what it was given: inside the code property, the max of the Length constraint moved from 4 to 8.');
    $bound->refine(['e' => ['mode' => 'none']]);
  }

  /**
   * Tests a mount that holds two structures composes each at its own path.
   */
  public function testMountOfTwoStructures(): void {
    $builder = new SurfaceBuilder(['title' => DataDefinition::create('string')->setLabel('Title')]);
    $builder->mountStructure('surface', new Attachment(static::child()));
    $builder->mountStructure('user', new Attachment(static::child()));
    $surface = $builder->seal();
    $code = static fn (SurfaceInterface $refined, string $at): mixed => DefinitionSchema::propertyOf(DefinitionSchema::propertyOf($refined->getDefinitions()->entry('third_party_settings')->schema(), $at) ?? Schema::boolean(FALSE), 'code')?->get('maxLength');
    $one = $surface->refine(['third_party_settings' => ['user' => ['mode' => 'narrow']]]);
    $this->assertTrue($one->getDefinitions()->entry('third_party_settings')->isSchemaFirst());
    $this->assertSame(4, $code($one, 'user'), 'The second structure refined, at its own path.');
    $this->assertSame(8, $code($one, 'surface'), 'The first left as sealed.');
    $mounts = ['surface' => ['mode' => 'narrower'], 'user' => ['mode' => 'narrow']];
    $both = $surface->refine(['third_party_settings' => $mounts]);
    $this->assertSame(2, $code($both, 'surface'));
    $this->assertSame(4, $code($both, 'user'));
  }

  /**
   * Tests the walk of what is ruled out gives what the definition walk gave.
   *
   * For a list, a value and a boolean schema: nothing; for a map with a key
   * ruled out at two depths: those keys.
   */
  public function testTheRuledOutWalk(): void {
    $item = MapDataDefinition::create()->setPropertyDefinition('a', DoesNotApply::marked(DataDefinition::create('string')));
    $list = new ListDataDefinition([], $item);
    $this->assertSame([], SchemaFacts::ruledOutInside(DefinitionSchema::of($list)), 'A list: nothing, its item\'s keys included.');
    $this->assertSame([], SchemaFacts::ruledOutInside(DefinitionSchema::of(DoesNotApply::marked(DataDefinition::create('string')))), 'A value: nothing.');
    $this->assertSame([], SchemaFacts::ruledOutInside(Schema::boolean(TRUE)), 'A boolean schema: nothing.');
    $map = MapDataDefinition::create()
      ->setPropertyDefinition('out', DoesNotApply::marked(DataDefinition::create('string')))
      ->setPropertyDefinition('in', MapDataDefinition::create()
        ->setPropertyDefinition('deep', DoesNotApply::marked(MapDataDefinition::create()->setPropertyDefinition('z', DoesNotApply::marked(DataDefinition::create('string')))))
        ->setPropertyDefinition('kept', DataDefinition::create('string')))
      ->setPropertyDefinition('list', $list);
    $this->assertSame(['out', 'in.deep'], SchemaFacts::ruledOutInside(DefinitionSchema::of($map)), 'A map: its ruled-out keys, a ruled-out key not walked into, a list not walked into.');
  }

  /**
   * Tests a locked deciding key's variant is not swapped by the values.
   *
   * The key holds the locked variant's map; values naming another variant
   * would make it gain and lose keys, which the narrowing check of the
   * whole shell refuses, as it did before.
   */
  public function testLockedVariantIsNotSwapped(): void {
    $surface = static::shells(static::child());
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "fixed" for the child attached there widened what it was given: the map gained the property other.');
    $surface->refine(['lock' => 'y', 'fixed' => []]);
  }

  /**
   * Tests a key ruled out inside a refined shell is left out by its readers.
   *
   * The accept leaves out a value for a key the child ruled out and the
   * caller did not send, and the read tool's values leave it out
   * (DoesNotApply::leftOut()), at an attachment and at a mount.
   */
  public function testRuledOutKeyInsideIsLeftOut(): void {
    $surface = static::shells(static::child());
    $current = [
      'att' => ['mode' => 'out', 'code' => 'held'],
      'third_party_settings' => ['surface' => ['mode' => 'out', 'code' => 'held']],
    ];
    $input = [
      'att' => ['mode' => 'out'],
      'third_party_settings' => ['surface' => ['mode' => 'out']],
    ];
    $accepted = $this->pipeline()->accept($surface, $input, $current);
    $this->assertArrayNotHasKey('code', $accepted['att'], 'The accept leaves a key ruled out inside an attachment out.');
    $this->assertArrayNotHasKey('code', $accepted['third_party_settings']['surface'], 'And inside a structure at a mount.');
    $read = DoesNotApply::leftOut($surface, $current);
    $this->assertSame(['mode' => 'out'], $read['att'], 'The read tool leaves it out inside an attachment.');
    $this->assertSame(['mode' => 'out'], $read['third_party_settings']['surface'], 'And at a mount.');
  }

  /**
   * Builds a mount whose key its own refiner narrows to a list of values.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   `gate`, `third_party_settings.surface.level` (one of 1 to 4, one of
   *   1 and 2 once `gate` is `narrow`) and `room`, which watches the level.
   */
  protected function narrowedMount(): SurfaceInterface {
    $builder = new SurfaceBuilder([
      'gate' => DataDefinition::create('string')->setLabel('Gate'),
      'room' => DataDefinition::create('string')->setLabel('Room'),
    ]);
    $level = DataDefinition::create('integer')->setLabel('Level')->addConstraint('Choice', ['choices' => [1, 2, 3, 4]]);
    $builder->setThirdPartyDefinition('surface', 'level', $level);
    $builder->addRefinement('third_party_settings.surface.level', ['gate']);
    $builder->addRefiner('third_party_settings', new HandRefiner(['narrow' => [['choice', 'surface.level', [1, 2]]]]));
    $builder->addRefinement('room', ['third_party_settings.surface.level']);
    $builder->addRefiner('room', new HandRefiner([]));
    $builder->setWatchedValueCheck($this->container->get('surface.watched_value_check'));
    return $builder->seal();
  }

  /**
   * Tests a watched key inside a refined map is judged by its refined schema.
   *
   * A mounted key a refiner watches holds a value its mount's own refiner
   * no longer allows: the refinement withholds it from the watching
   * refiner (Surface::refusedWatchedValues(), a walk of the refined
   * mount's schema); while the mount allows it, it is handed on.
   */
  public function testWatchedKeyInsideRefinedMapIsWithheld(): void {
    $surface = $this->narrowedMount();
    $values = ['gate' => 'narrow', 'room' => 'x', 'third_party_settings' => ['surface' => ['level' => 3]]];
    $this->assertSame(['third_party_settings.surface.level' => 3], $surface->refine($values)->withheldValues());
    $this->assertSame([], $surface->refine(['gate' => 'open'] + $values)->withheldValues());
  }

  /**
   * Tests a stored choice a refined map no longer offers is orphaned.
   *
   * The form's extraction reads the mounted key's schema in the refined
   * mount (Extracting::schemaAt()) and asks the options service whether it
   * still offers the stored value (isOrphaned()), and whether the input
   * still stands (stillStands()).
   */
  public function testOrphanedChoiceInRefinedForm(): void {
    $surface = $this->narrowedMount();
    $stored = ['gate' => 'open', 'third_party_settings' => ['surface' => ['level' => 3]]];
    $values = ['gate' => 'narrow'] + $stored;
    $refined = $surface->refine($values);
    $extracting = (new \ReflectionProperty($this->formBuilder(), 'extracting'))->getValue($this->formBuilder());
    $orphaned = new \ReflectionMethod($extracting, 'isOrphaned');
    $this->assertTrue($orphaned->invoke($extracting, $refined, 'third_party_settings.surface.level', ['gate'], $values, $stored), 'A stored level the narrowed mount no longer offers is orphaned.');
    $this->assertFalse($orphaned->invoke($extracting, $surface->refine($stored), 'third_party_settings.surface.level', ['gate'], $stored, $stored), 'Not while what it refines against holds what is stored.');
    $stands = new \ReflectionMethod($extracting, 'stillStands');
    $this->assertFalse($stands->invoke($extracting, $refined, 'third_party_settings.surface.level', [], 3, $values, $values), 'An input the narrowed mount no longer offers does not stand.');
    $this->assertTrue($stands->invoke($extracting, $refined, 'third_party_settings.surface.level', [], 2, $values, $values), 'One it offers does.');
  }

  /**
   * Tests a woken refined shell holds its child's very schemas.
   */
  public function testWokenShellHoldsTheSchemasOfItsChild(): void {
    $child = static::child();
    $surface = static::shells($child);
    $refined = $surface->refine(static::at('narrow'));
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($refined));
    $entry = $woken->getDefinitions()->entry('att');
    $this->assertInstanceOf(Entry::class, $entry);
    $this->assertTrue($entry->isSchemaFirst());
    $sealed = $entry->attachment->child->getDefinitions();
    $this->assertSame($sealed->entry('note')?->schema(), DefinitionSchema::propertyOf($entry->schema(), 'note'), 'A key the child left as it was: the woken child\'s very schema.');
    $this->assertNotSame($sealed->entry('code')?->schema(), DefinitionSchema::propertyOf($entry->schema(), 'code'), 'A key the child refined: the shell\'s own.');
    $this->assertSame(4, DefinitionSchema::propertyOf($entry->schema(), 'code')?->get('maxLength'));
  }

}
