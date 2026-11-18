<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Entry;
use Drupal\surface\SettlesChildInterface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\DoorCorpusRecorder;
use Drupal\Tests\surface\HandCorpus\DoorSurfaces;
use Drupal\Tests\surface\HandCorpus\HandSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PortableSchema\Builder\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a sealed entry holds its schema, and derives a definition on demand.
 *
 * The seal makes every entry schema-first (SurfaceBuilder::seal(),
 * DefinitionMap::fromArrays() with schemaFirst, Entry::sealed()): its schema
 * is the source, read from the definition its source gave, or for a key
 * that holds a child frame composed of the key's own facts around the
 * schemas its child's entries hold, and its definition is derived from the
 * schema on the first ask (DefinitionFromSchema::of()). This test holds:
 * - every sealed entry of the examples, a test module and the hand and
 *   door surfaces is schema-first, at every depth, but the one a hand case
 *   keeps definition-first on purpose, and the test names any other;
 * - a sealed data surface, serialized and unserialized, answers schema()
 *   and definition() as before for every entry;
 * - a key of a class no schema derives a definition of stays
 *   definition-first, a key that holds a child frame with such a key
 *   inside it too (made whole, Entry::whole()), and the surface works;
 * - the seal builds no definition for a shell and derives none: no entry
 *   it makes holds a definition, and each shell's schema holds the very
 *   schemas its child's entries hold;
 * - a definition a sealed entry handed out is for reading: with
 *   assertions on, one written is refused at the next ask, for a key and
 *   for a key that holds a child frame.
 *
 * Decision: see docs/decisions.md#a-sealed-entry-holds-its-schema.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SealedEntryHoldsItsSchemaTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use SealedShellsTrait;
  use UserCreationTrait;

  /**
   * The modules whose data surfaces are walked: the examples and a test module.
   */
  protected const MODULES = [
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_first',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_values_test',
  ];

  /**
   * The data surfaces walked and the entries they hold, at every depth.
   */
  protected const COUNTS = [194, 917];

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
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_values_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
    $this->installConfig(['surface']);
  }

  /**
   * Gives every sealed data surface this test walks, by where it is.
   *
   * @return array<string, \Drupal\surface\SurfaceInterface>
   *   The data surfaces.
   */
  protected function sealedSurfaces(): array {
    $surfaces = [];
    foreach ([TRUE, FALSE] as $declared) {
      foreach ($this->examples($declared, self::MODULES) as [$id, $operation, $surface]) {
        $surfaces[($declared ? 'declared ' : '') . $id . ':' . ($operation ?? '-')] = $surface;
      }
    }
    foreach ([...HandSurfaces::NAMES, ...DoorSurfaces::NAMES] as $name) {
      [$surface, , , $alternates] = in_array($name, HandSurfaces::NAMES, TRUE) ? HandSurfaces::build($name) : DoorSurfaces::build($name);
      $surfaces["hand $name"] = $surface;
      foreach ($alternates as $form => [$each]) {
        $surfaces["hand $name $form"] = $each;
      }
    }
    $surfaces['every shell'] = static::everyShell()[0];
    return $surfaces;
  }

  /**
   * Walks a data surface's entries and those of every settled child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $at
   *   Where it is.
   * @param \Closure(\Drupal\surface\Entry, string): void $each
   *   Called with each entry and where it is.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   *
   * @return int
   *   The data surfaces walked.
   */
  protected static function walk(SurfaceInterface $surface, string $at, \Closure $each, array &$seen = []): int {
    if (isset($seen[spl_object_id($surface)])) {
      return 0;
    }
    $seen[spl_object_id($surface)] = TRUE;
    $walked = 1;
    foreach (['in' => $surface->getDefinitions(), 'out' => $surface->getOutputDefinitions()] as $side => $map) {
      foreach ($map->entries() as $name => $entry) {
        $here = "$at $side:$name";
        $each($entry, $here);
        $children = ['child' => $entry->attachment?->settledChild(), 'item' => $entry->list?->item->settledChild()];
        foreach ($entry->variants->variants ?? [] as $id => $variant) {
          $children["variant $id"] = $variant->settledChild();
        }
        foreach ($entry->mounted as $path => $mounted) {
          $children["mount $path"] = $mounted->settledChild();
        }
        foreach (array_filter($children) as $what => $child) {
          $walked += static::walk($child, "$here $what", $each, $seen);
        }
      }
    }
    return $walked;
  }

  /**
   * Tests every sealed entry is schema-first, at every depth.
   */
  public function testEverySealedEntryIsSchemaFirst(): void {
    $kept = [];
    $entries = 0;
    $walked = 0;
    $seen = [];
    foreach ($this->sealedSurfaces() as $at => $surface) {
      $walked += static::walk($surface, $at, static function (Entry $entry, string $here) use (&$kept, &$entries): void {
        $entries++;
        if (!$entry->isSchemaFirst()) {
          $kept[] = $here;
        }
      }, $seen);
    }
    // The ruled hand surface's m1 holds a key of NotAMapDefinition, a test
    // class no schema derives a definition of, on purpose.
    $this->assertSame(['hand ruled in:m1'], array_slice($kept, 0, 20), 'Every sealed entry is schema-first but the one a hand case keeps definition-first.');
    $this->assertSame(self::COUNTS, [$walked, $entries], 'The data surfaces walked and the entries they hold.');
  }

  /**
   * Tests a sealed data surface wakes from serialize() answering as before.
   *
   * For every entry, its schema and its definition, compared by what they
   * say (the woken objects are other objects): the schema as plain data,
   * the definition as the door corpus writes its tree.
   */
  public function testSealedSurfaceWakesAlike(): void {
    $differ = [];
    $compared = 0;
    foreach ($this->sealedSurfaces() as $at => $surface) {
      // Serialized first: a child settled while it is serialized is walked
      // on both sides.
      $bytes = serialize($surface);
      $before = [];
      static::walk($surface, $at, static function (Entry $entry, string $here) use (&$before): void {
        if (!$entry->isSchemaFirst()) {
          return;
        }
        $before[$here] = [static::said($entry->schema()), DoorCorpusRecorder::tree($entry->definition())];
      });
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $woken = unserialize($bytes);
      static::walk($woken, $at, static function (Entry $entry, string $here) use (&$before, &$differ, &$compared): void {
        if (!$entry->isSchemaFirst()) {
          $differ[] = "$here: kept definition-first";
          return;
        }
        $compared++;
        if (!isset($before[$here])) {
          $differ[] = "$here: woken only";
          return;
        }
        if (static::said($entry->schema()) !== $before[$here][0]) {
          $differ[] = "$here: its schema";
        }
        if (DoorCorpusRecorder::tree($entry->definition()) !== $before[$here][1]) {
          $differ[] = "$here: its definition";
        }
      });
    }
    // The one entry kept definition-first, m1 of the ruled hand surface,
    // serializes its definition, and core's map keeps no properties on
    // sleep: its NotAMapDefinition wakes without them, as any entry made
    // definition-first of it does (DefinitionMap::fromArrays() without
    // schemaFirst). It is not read here.
    $this->assertSame(['hand ruled in:m1: kept definition-first'], array_slice($differ, 0, 20), 'Every woken schema-first entry answers schema() and definition() as before.');
    $this->assertGreaterThan(1000, $compared);
  }

  /**
   * Writes a schema as plain data: each text as its string and its class.
   *
   * @param mixed $value
   *   A schema, or a value inside one.
   *
   * @return mixed
   *   The plain data.
   */
  protected static function said(mixed $value): mixed {
    if ($value instanceof Schema) {
      return [
        '@schema' => $value->isBoolean() ? $value->toDocument() : static::said($value->all()),
        '@required' => $value->isRequired(),
      ];
    }
    if (is_array($value)) {
      return [
        '@keys' => array_map('strval', array_keys($value)),
        '@values' => array_map(static::said(...), array_values($value)),
      ];
    }
    if ($value instanceof \Stringable) {
      return ['@text' => (string) $value, '@class' => $value::class];
    }
    if (is_object($value)) {
      return ['@class' => $value::class, '@members' => static::said((array) $value)];
    }
    return $value;
  }

  /**
   * Tests a key of a class no schema derives stays definition-first.
   *
   * At the top and inside an attached child: the key holding the child
   * stays definition-first too, its definition made whole from its own
   * facts and the child's definitions, and the surface accepts, validates
   * and serves as it does.
   */
  public function testClassOutsideTheDerivedStaysDefinitionFirst(): void {
    $own = new class(['type' => 'string', 'label' => 'Own']) extends DataDefinition {};
    $child = (new SurfaceBuilder(['own' => clone $own, 'plain' => DataDefinition::create('string')->setLabel('Plain')]))->seal();
    $builder = new SurfaceBuilder([
      'own' => $own,
      'plain' => DataDefinition::create('integer')->setLabel('Plain'),
      'holder' => MapDataDefinition::create()->setLabel('Holder'),
    ]);
    $builder->attach('holder', new Attachment($child));
    $surface = $builder->seal();
    $entries = $surface->getDefinitions();
    $this->assertFalse($entries->entry('own')->isSchemaFirst(), 'A key of its own class stays definition-first.');
    $this->assertTrue($entries->entry('plain')->isSchemaFirst());
    $holder = $entries->entry('holder');
    $this->assertFalse($holder->isSchemaFirst(), 'A shell whose child holds such a key stays definition-first.');
    $whole = $holder->definition();
    $this->assertInstanceOf(MapDataDefinition::class, $whole);
    $this->assertSame(['own', 'plain'], array_keys($whole->getPropertyDefinitions()), 'Its definition is made whole.');
    $this->assertSame($child->getDefinitions()->entry('own')->definition(), $whole->getPropertyDefinition('own'), 'Of the child\'s own definitions.');
    $this->assertTrue(DefinitionSchema::same($holder->schema(), DefinitionSchema::of($holder->definition())));
    $values = ['own' => 'a', 'plain' => 3, 'holder' => ['own' => 'b', 'plain' => 'c']];
    $accepted = $this->pipeline()->accept($surface, $values, $surface->getDefaultValues());
    $this->assertSame(['own' => 'b', 'plain' => 'c'], $accepted['holder']);
    $this->assertSame([], $this->pipeline()->validate($surface, $accepted, $surface->getDefaultValues())->keys());
    $document = $this->container->get('surface.contract_emitter')->emitFull($surface, 'test.own')->document;
    $this->assertSame(['own', 'plain', 'holder'], array_keys($document['schema']['properties']));
    $this->assertSame(['own', 'plain'], array_keys($document['schema']['$defs']['test.own.holder']['properties']));
  }

  /**
   * Tests the seal builds no definition for a shell, and derives none.
   *
   * An attachment, a collection, variants chosen at the seal and
   * variants open, a shell inside a shell and inside a collection's item,
   * and a mount: no entry of the surface or of a child holds a definition
   * (Entry::definition() derives one on the first ask and keeps it, so the
   * private member says whether anything asked), and each shell's schema
   * holds the very schemas its child's entries hold.
   */
  public function testTheSealBuildsNoShellDefinition(): void {
    [$surface, , $children] = static::everyShell();
    $derived = new \ReflectionProperty(Entry::class, 'definition');
    $asked = [];
    $count = 0;
    foreach (['surface' => $surface] + $children as $at => $each) {
      foreach ($each->getDefinitions()->entries() as $name => $entry) {
        $count++;
        $this->assertTrue($entry->isSchemaFirst(), "$at.$name is schema-first.");
        // The mount's keys are deep copies of the structure's definitions
        // (SurfaceBuilder::mountStructure()), asked for when it is mounted.
        if ($derived->getValue($entry) !== NULL && $at !== 'holder') {
          $asked[] = "$at.$name";
        }
      }
    }
    $this->assertSame([], $asked, 'No entry the seal made holds a definition.');
    $this->assertSame(12, $count);
    $entries = $surface->getDefinitions();
    $holder = $children['holder'];
    $place = $entries->entry('place')->schema();
    foreach ($holder->getDefinitions()->entries() as $name => $entry) {
      $this->assertSame($entry->schema(), DefinitionSchema::propertyOf($place, (string) $name), "place.$name is its entry's schema.");
    }
    $item = DefinitionSchema::itemOf($entries->entry('rows')->schema());
    $this->assertSame($holder->getDefinitions()->entry('inner')?->schema(), $item === NULL ? NULL : DefinitionSchema::propertyOf($item, 'inner'), 'rows.*.inner is its entry\'s schema.');
    $pick = $entries->entry('pick')->schema();
    foreach ($children['leaf']->getDefinitions()->entries() as $name => $entry) {
      $this->assertSame($entry->schema(), DefinitionSchema::propertyOf($pick, (string) $name), "pick.$name is the chosen variant's entry's schema.");
    }
  }

  /**
   * Tests a shell's own default is served by the tool door.
   *
   * The seal hands a shell its own facts alone, its default among them
   * (SealedShellsTrait: `place` and `rows` hold one): the composed schema
   * holds it, and the tool door's input definition gives it.
   */
  public function testShellsOwnDefaultIsServed(): void {
    [$surface] = static::everyShell();
    $this->assertSame(['title' => 'Home'], SchemaFacts::default($surface->getDefinitions()->entry('place')->schema()));
    $map = $this->container->get('surface.input_definitions')->fromSurface($surface, 'Every shell', '');
    $this->assertSame(['title' => 'Home'], $map->getPropertyDefinition('place')?->getDefaultValue(), 'The attached shell\'s own default.');
    $this->assertSame([['title' => 'First']], $map->getPropertyDefinition('rows')?->getDefaultValue(), 'The collection\'s own default.');
  }

  /**
   * Tests a definition a sealed entry handed out is refused once written.
   *
   * With assertions on, every later ask of a schema-first entry's
   * definition reads it again, and one written since differs from the
   * schema: for a key and for a key that holds a child frame, written at a
   * key inside it.
   */
  public function testWrittenDefinitionIsRefusedAtTheNextAsk(): void {
    [$surface] = static::everyShell();
    $entries = $surface->getDefinitions();
    $kind = $entries->entry('kind');
    $label = $kind->definition();
    $this->assertInstanceOf(DataDefinition::class, $label);
    $label->setLabel('Written');
    try {
      $kind->definition();
      $this->fail('A written definition is refused at the next ask.');
    }
    catch (\AssertionError $e) {
      $this->assertStringContainsString('The definition of the "kind" key, derived from the schema its source gave, was written after it was derived.', $e->getMessage());
    }
    $place = $entries->entry('place');
    $map = $place->definition();
    $this->assertInstanceOf(MapDataDefinition::class, $map);
    $title = $map->getPropertyDefinition('title');
    $this->assertInstanceOf(DataDefinition::class, $title);
    $title->setRequired(FALSE);
    try {
      $place->definition();
      $this->fail('A shell\'s written definition is refused at the next ask.');
    }
    catch (\AssertionError $e) {
      $this->assertStringContainsString('The definition of the "place" key, derived from the schema its source gave, was written after it was derived.', $e->getMessage());
    }
    // The schema is the source and was not written: what is served stands.
    $this->assertContains('title', (array) $place->schema()->get('required'));
  }

  /**
   * Tests a deferred child settled at the seal hands its messages as sources.
   *
   * A variant held unsettled is built when the seal composes the key that
   * chose it (Entry::sealed(), inside DefinitionMap::fromArrays()), after
   * the parent's ConstraintMessages::sources() and assertGroupsAgree() ran.
   * The child was sealed by its own seal, which rewrote its t() message to
   * its source string, so the validator is handed that string: on the
   * child's key and on the parent's composed schema alike.
   */
  public function testDeferredChildHandsItsMessageAsItsSource(): void {
    $deferred = new class () implements SettlesChildInterface {

      /**
       * The child, once built.
       */
      public ?SurfaceInterface $child = NULL;

      /**
       * {@inheritdoc}
       */
      public function settle(): SurfaceInterface {
        return $this->child ??= (new SurfaceBuilder([
          'code' => DataDefinition::create('string')->setLabel('Code')
            ->addConstraint('Length', ['max' => 3, 'maxMessage' => new TranslatableMarkup('Three at most.')]),
        ]))->seal();
      }

      /**
       * {@inheritdoc}
       */
      public function settled(): ?SurfaceInterface {
        return $this->child;
      }

      /**
       * {@inheritdoc}
       */
      public function holdsNoSecret(): bool {
        return TRUE;
      }

    };
    $builder = new SurfaceBuilder([
      'kind' => DataDefinition::create('string')->setLabel('Kind'),
      'pick' => MapDataDefinition::create()->setLabel('Pick'),
    ]);
    $builder->attachVariants('pick', 'kind', ['x' => new Attachment($deferred)]);
    $builder->setDefault('kind', 'x');
    $builder->lock('kind');
    $this->assertNull($deferred->settled(), 'The variant is held unsettled until the seal.');
    $surface = $builder->seal();
    $this->assertNotNull($deferred->settled(), 'The seal settled it to compose the chosen key.');
    $own = SchemaFacts::constraints($deferred->settled()->getDefinitions()->entry('code')->schema())['Length']['maxMessage'] ?? NULL;
    $this->assertSame('Three at most.', $own, 'The child\'s key holds the message as its source.');
    $code = DefinitionSchema::propertyOf($surface->getDefinitions()->entry('pick')->schema(), 'code');
    $this->assertNotNull($code);
    $this->assertSame('Three at most.', SchemaFacts::constraints($code)['Length']['maxMessage'] ?? NULL, 'The parent\'s composed schema holds it as its source too.');
    $refused = [];
    foreach ($this->pipeline()->validate($surface, ['kind' => 'x', 'pick' => ['code' => 'abcdef']]) as $violation) {
      $refused[] = (string) $violation->message;
    }
    $this->assertContains('Three at most.', $refused, 'The validator says it.');
  }

}
