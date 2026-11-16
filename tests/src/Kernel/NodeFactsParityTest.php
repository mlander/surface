<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\Core\TypedData\Plugin\DataType\Uri;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Reference\References;
use Drupal\surface\Resolve;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_values_test\HandBuiltSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests each reader of a node against the definition call it stands for.
 *
 * The pipeline, the validator's own checks and the document's classes
 * read a key's facts from its node (NodeFacts, and the node readers of
 * DefinitionSchema and Secrets). For every entry of every data
 * surface of the 19 example modules and of `surface_values_test`
 * (HandBuiltSurfaces included), declared and refined by each of its value
 * sets, and for every definition below an entry at every depth (a
 * property, an item, a shape's, a config type's and `each`'s definition),
 * each reader on the node answers what the definition call it stands for
 * answers (NodeFactsParityTrait::READERS), by strict equality. The counts
 * are pinned as literals, so a walk that finds nothing fails.
 *
 * Since the seal makes every entry node-first (2026-11-15), a sealed
 * example's definition is derived from the very node it is compared with
 * (NodeDefinition::of()). So the example walk proves the inverse says what
 * the node says. It no longer proves that DefinitionSchema::of() reads an
 * authored definition faithfully: a reading fault the derivation mirrors
 * would pass it. That is proven by the hand cases (testHandCases()):
 * definitions built by hand and read with of(), at least one of which
 * states each reader's fact.
 *
 * Decision: see docs/decisions.md#the-value-pipeline-reads-nodes,
 * docs/decisions.md#the-validators-checks-and-the-document-read-nodes.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class NodeFactsParityTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use NodeFactsParityTrait;
  use UserCreationTrait;

  /**
   * The example modules whose declarations are walked.
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
   * The data surfaces walked and the entries compared.
   */
  protected const COUNTS = [
    'surfaces' => 327,
    'entries' => 1506,
  ];

  /**
   * The comparisons made, by reader, over the examples.
   *
   * Each distinct node is compared once, wherever it is first met
   * (parity()), against the definition derived from it (see the class
   * docblock). 1,067 while each distinct definition was: the two keys of
   * example 3's compliance mount, license and stewards, were compared
   * twice, as the mount's deep copy and as the structure's own entry (or
   * its refined copy), which hold one node. Since 2026-11-15 they are
   * compared once, in either form.
   */
  protected const READS = [
    'arrangement' => 1065,
    'compute' => 1065,
    'configType' => 1065,
    'configTypeNode' => 1065,
    'constraints' => 1065,
    'constraintsJson' => 1065,
    'dataType' => 1065,
    'dateOnly' => 1065,
    'declaredConstraints' => 1065,
    'default' => 1065,
    'defaultOf' => 1065,
    'description' => 1065,
    'doesNotApply' => 1065,
    'each' => 1065,
    'hasConstraints' => 1065,
    'hasDefault' => 1065,
    'holdsValue' => 1065,
    'isAlwaysHeld' => 1065,
    'isComplex' => 1065,
    'isComputed' => 1065,
    'isFixed' => 1065,
    'isGrouping' => 1065,
    'isHeldWhole' => 1065,
    'isLeaf' => 1065,
    'isList' => 1065,
    'isMap' => 1065,
    'isNullable' => 1065,
    'isOneVariant' => 1065,
    'isReadOnly' => 1065,
    'isRequired' => 1065,
    'keywordsOf' => 1065,
    'label' => 1065,
    'ownLabel' => 1065,
    'placedAfter' => 1065,
    'properties' => 1065,
    'readVariants' => 1065,
    'resolve' => 1065,
    'secret' => 1065,
    'setting' => 1065,
    'settings' => 1065,
    'shape' => 1065,
    'shapeNode' => 1065,
    'storedByUuid' => 1065,
    'typeOf' => 1065,
    'valueClass' => 1065,
    'variantsBy' => 1065,
    'variantsFrom' => 1065,
  ];

  /**
   * The comparisons whose definition states the fact, by reader.
   *
   * Over the examples, whose definitions are derived from their nodes.
   * Every reader also has a definition built by hand that states its
   * fact (testHandCases()).
   */
  protected const STATED = [
    'arrangement' => 43,
    'compute' => 5,
    'configType' => 4,
    'configTypeNode' => 4,
    'constraints' => 682,
    'constraintsJson' => 682,
    'dataType' => 1065,
    'dateOnly' => 1,
    'declaredConstraints' => 351,
    'default' => 241,
    'defaultOf' => 440,
    'description' => 359,
    'doesNotApply' => 41,
    'each' => 4,
    'hasConstraints' => 682,
    'hasDefault' => 296,
    'holdsValue' => 276,
    'isAlwaysHeld' => 77,
    'isComplex' => 320,
    'isGrouping' => 29,
    'isLeaf' => 702,
    'isList' => 43,
    'isMap' => 301,
    'isNullable' => 774,
    'isRequired' => 199,
    'keywordsOf' => 675,
    'label' => 1027,
    'ownLabel' => 1027,
    'placedAfter' => 15,
    'properties' => 301,
    'readVariants' => 102,
    'resolve' => 3,
    'secret' => 18,
    'setting' => 82,
    'settings' => 82,
    'shape' => 10,
    'shapeNode' => 10,
    'storedByUuid' => 4,
    'typeOf' => 654,
    'valueClass' => 1065,
    'variantsBy' => 46,
    'variantsFrom' => 102,
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
   * Tests every reader on every example entry, at every depth.
   */
  public function testEveryExampleEntryAgrees(): void {
    $sources = [];
    foreach ($this->examples(FALSE, [...self::WALKED, 'surface_values_test']) as [$id, $operation, $surface]) {
      $sources[$id . ($operation === NULL ? '' : ':' . $operation)] = $surface;
    }
    $sources[HandBuiltSurfaces::ID] = HandBuiltSurfaces::surface();
    $sources[HandBuiltSurfaces::UNWRITTEN_ID] = HandBuiltSurfaces::unwritten();
    /** @var \SplObjectStorage<\Drupal\surface\SurfaceInterface, true> $walked */
    $walked = new \SplObjectStorage();
    /** @var \SplObjectStorage<\PortableSchema\Builder\Schema, null> $seen */
    $seen = new \SplObjectStorage();
    $counts = [];
    $stated = [];
    $differ = [];
    $entries = 0;
    foreach ($sources as $name => $surface) {
      $refined = ["$name declared" => $surface];
      // The value sets are read from a served document, which refuses the
      // types no row is for: that surface is walked as declared.
      $sets = $name === HandBuiltSurfaces::UNWRITTEN_ID ? [] : $this->valueSets($surface);
      foreach ($sets as $set => $values) {
        $refined["$name [$set]"] = $surface->refine($values);
      }
      foreach ($refined as $at => $each) {
        $entries += $this->compareSurface($each, $at, $walked, $seen, $counts, $differ, $stated);
      }
    }
    $this->assertSame([], array_slice($differ, 0, 40), count($differ) . ' disagreements.');
    $this->assertSame(self::COUNTS, ['surfaces' => count($walked), 'entries' => $entries]);
    ksort($counts);
    $this->assertSame(self::READS, $counts, 'The comparisons made, by reader.');
    ksort($stated);
    $this->assertSame(self::STATED, $stated, 'The comparisons whose definition states the fact, by reader.');
  }

  /**
   * Tests a hand case for each fact the examples do not state.
   */
  public function testHandCases(): void {
    $counts = [];
    $stated = [];
    foreach ($this->handCases() as $case => $definition) {
      $differ = static::parity($definition, DefinitionSchema::of($definition), $case, $counts, NULL, $stated);
      $this->assertSame([], $differ, $case);
    }
    // The examples' definitions are derived from the very nodes they are
    // compared with: only a definition built by hand shows the read says
    // what an author's definition says. Each reader has one that states
    // its fact.
    foreach (array_keys(self::READERS) as $reader) {
      $this->assertGreaterThan(0, $counts[$reader] ?? 0, "A hand case compares $reader.");
      $this->assertGreaterThan(0, $stated[$reader] ?? 0, "A definition built by hand states $reader.");
    }
  }

  /**
   * Compares every entry of a data surface and of each child, once.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $at
   *   Where it is, for a difference.
   * @param \SplObjectStorage<\Drupal\surface\SurfaceInterface, true> $walked
   *   The surfaces walked.
   * @param \SplObjectStorage<\PortableSchema\Builder\Schema, null> $seen
   *   The nodes compared.
   * @param array<string, int> $counts
   *   The comparisons made, by reader.
   * @param list<string> $differ
   *   The disagreements.
   * @param array<string, int> $stated
   *   The comparisons whose definition states the fact, by reader.
   *
   * @return int
   *   The entries compared here.
   */
  protected function compareSurface(SurfaceInterface $surface, string $at, \SplObjectStorage $walked, \SplObjectStorage $seen, array &$counts, array &$differ, array &$stated): int {
    if ($walked->contains($surface)) {
      return 0;
    }
    $walked->attach($surface, TRUE);
    $entries = 0;
    foreach (['in' => $surface->getDefinitions(), 'out' => $surface->getOutputDefinitions()] as $side => $map) {
      foreach ($map->entries() as $name => $entry) {
        $here = "$at $side:$name";
        $entries++;
        array_push($differ, ...static::parity($entry->definition(), $entry->schema(), $here, $counts, $seen, $stated));
        $children = [];
        if ($entry->variants !== NULL) {
          foreach ($entry->variants->variantIds() as $variant) {
            $children["variant $variant"] = $entry->variants->variant($variant)->settledChild();
          }
        }
        if ($entry->attachment !== NULL) {
          $children['child'] = $entry->attachment->settledChild();
        }
        if ($entry->list !== NULL) {
          $children['item'] = $entry->list->item->settledChild();
        }
        foreach ($entry->mounted as $path => $mounted) {
          $children["mounted $path"] = $mounted->settledChild();
        }
        foreach (array_filter($children) as $what => $child) {
          $entries += $this->compareSurface($child, "$here $what", $walked, $seen, $counts, $differ, $stated);
        }
      }
    }
    return $entries;
  }

  /**
   * Builds one definition for each fact the examples do not state.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The definitions, by case.
   */
  protected function handCases(): array {
    $cases = [];
    $cases['a label of \'\''] = DataDefinition::create('string')->setLabel('');
    $cases['a label of NULL'] = new DataDefinition(['type' => 'string', 'label' => NULL]);
    $cases['no label'] = DataDefinition::create('string');
    // A key that refers to one content entity type, stored by uuid, and
    // one whose author kept the id.
    $cases['stored by uuid'] = DataDefinition::create('integer')->setLabel('Page')->setSetting(References::CONTENT, TRUE);
    $cases['stored by id'] = DataDefinition::create('integer')->setLabel('Page')->setSetting(References::CONTENT, TRUE)->setSetting(References::STORED_AS, References::BY_ID);
    // A value whose definition names its own class.
    $cases['a class of its own'] = new DataDefinition(['type' => 'string', 'label' => 'Code', 'class' => Uri::class]);

    // A map's own default over the defaults of a map below it: merged at
    // every depth.
    $r = DataDefinition::create('string')->setLabel('R');
    DefinitionMetadata::setDefaultValue($r, 'r of q');
    $s = DataDefinition::create('string')->setLabel('S');
    DefinitionMetadata::setDefaultValue($s, 's of q');
    $deep = MapDataDefinition::create()->setLabel('P')
      ->setPropertyDefinition('q', MapDataDefinition::create()->setLabel('Q')->setPropertyDefinition('r', $r)->setPropertyDefinition('s', $s));
    DefinitionMetadata::setDefaultValue($deep, ['q' => ['r' => 'own']]);
    $cases['a default over a nested default'] = $deep;

    $null = DataDefinition::create('string')->setLabel('None');
    DefinitionMetadata::setDefaultValue($null, NULL);
    $cases['an explicit NULL default'] = $null;

    $plain = DataDefinition::create('integer')->setLabel('Count');
    DefinitionMetadata::setDefaultValue($plain, 3);
    $cases['a default'] = $plain;

    $a = DataDefinition::create('string')->setLabel('A');
    DefinitionMetadata::setDefaultValue($a, 'from a');
    $b = DataDefinition::create('string')->setLabel('B');
    DefinitionMetadata::setDefaultValue($b, NULL);
    $c = DataDefinition::create('string')->setLabel('C');
    $map = MapDataDefinition::create()->setLabel('Map')
      ->setPropertyDefinition('a', $a)
      ->setPropertyDefinition('b', $b)
      ->setPropertyDefinition('c', $c);
    DefinitionMetadata::setDefaultValue($map, ['c' => 'own']);
    $cases['a map default with property defaults'] = $map;

    $inner = MapDataDefinition::create()->setLabel('Inner')->setPropertyDefinition('a', (clone $a));
    $outer = MapDataDefinition::create()->setLabel('Outer')->setPropertyDefinition('inner', $inner);
    $cases['a map whose default is its property\'s'] = $outer;
    $own = MapDataDefinition::create()->setLabel('Own')->setPropertyDefinition('a', DataDefinition::create('string'));
    DefinitionMetadata::setDefaultValue($own, 'not a map');
    $cases['a map whose own default is no array'] = $own;

    $secret = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($secret);
    DefinitionMetadata::setDefaultValue($secret, 'hidden');
    $cases['a secret with a default'] = $secret;

    $required = DataDefinition::create('string')->setLabel('Key')->setRequired(TRUE);
    DefinitionMetadata::setSecret($required);
    $cases['a required secret'] = $required;

    $not = DataDefinition::create('string')->setLabel('Plain');
    DefinitionMetadata::setSecret($not, FALSE);
    $cases['a secret stored as FALSE'] = $not;

    $held = DataDefinition::create('string')->setLabel('Held');
    DefinitionMetadata::setAlwaysHeld($held);
    $cases['an always held key'] = $held;

    $group = MapDataDefinition::create()->setLabel('Settings')
      ->setPropertyDefinition('x', DataDefinition::create('string')->setLabel('X'));
    DefinitionMetadata::setGrouping($group);
    $cases['grouping'] = $group;

    $owned = DataDefinition::create('string')->setLabel('Reworded');
    DefinitionMetadata::keepOwnLabel($owned);
    $owned->setLabel('Again');
    $cases['an own label kept'] = $owned;

    $cases['settings'] = DataDefinition::create('string')->setLabel('Set')->setSettings(['multiline' => TRUE]);
    $cases['a bare any'] = DataDefinition::create('any')->setLabel('Anything');
    $cases['decimal'] = DataDefinition::create('decimal')->setLabel('Amount');
    $cases['a datetime'] = DataDefinition::create('datetime_iso8601')->setLabel('When');
    $cases['a datetime with DateOnly'] = DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly', []);
    $cases['a string drawn as a date'] = DataDefinition::create('string')->setLabel('Day')->setSetting('widget', 'date');
    $cases['an empty MapDataDefinition'] = MapDataDefinition::create()->setLabel('Empty');

    $item = MapDataDefinition::create()->setLabel('Row')
      ->setPropertyDefinition('weight', DataDefinition::create('integer')->setLabel('Weight'));
    $cases['a list of maps'] = new ListDataDefinition(['label' => 'Rows'], $item);

    $hidden = DataDefinition::create('string')->setLabel('Code');
    DefinitionMetadata::setSecret($hidden);
    $cases['a list of secrets'] = new ListDataDefinition(['label' => 'Codes'], $hidden);

    // Core's own flags, and what DefinitionMetadata stores that no example
    // states.
    $cases['read-only'] = DataDefinition::create('string')->setLabel('Stamp')->setReadOnly(TRUE);
    $cases['computed, so read-only'] = DataDefinition::create('string')->setLabel('Derived')->setComputed(TRUE);
    $cases['read-only stored as FALSE beside computed'] = new DataDefinition([
      'type' => 'string',
      'label' => 'Typed over',
      'read-only' => FALSE,
      'computed' => TRUE,
    ]);
    $cases['a description of \'\''] = DataDefinition::create('string')->setLabel('Blank')->setDescription('');
    $fixed = DataDefinition::create('string')->setLabel('Fixed');
    DefinitionMetadata::setFixed($fixed);
    $cases['fixed'] = $fixed;
    $one = DataDefinition::create('string')->setLabel('Kind');
    DefinitionMetadata::setOneVariant($one);
    $cases['one variant'] = $one;
    $whole = DataDefinition::create('any')->setLabel('Whole');
    DefinitionMetadata::setHeldWhole($whole);
    $cases['an any held whole'] = $whole;

    // An authored definition for each fact the node-first examples no
    // longer compare with one an author built (testHandCases()): the
    // examples' definitions are now derived from the node they are read
    // against.
    $cases['a description'] = DataDefinition::create('string')->setLabel('Said')->setDescription('What it says.');
    $placed = DataDefinition::create('string')->setLabel('Placed');
    DefinitionMetadata::setPlacedAfter($placed, 'title');
    DefinitionMetadata::setArrangement($placed, new Arrangement(weight: 5, help: 'Help text.'));
    $cases['placed after a key, arranged'] = $placed;
    $computed = DataDefinition::create('string')->setLabel('Slug');
    DefinitionMetadata::setComputed($computed, new Computed(['title'], MachineName::class));
    $cases['computed from a key'] = $computed;
    $resolved = DataDefinition::create('string')->setLabel('Owner');
    DefinitionMetadata::setResolve($resolved, new Resolve(['account' => 'uid'], MachineName::class, SurfaceInterface::OWNER));
    $cases['resolved from a key'] = $resolved;
    $cases['marked as not applying'] = DoesNotApply::marked(DataDefinition::create('string')->setLabel('Ruled'));
    $variants = DataDefinition::create('any')->setLabel('Settings');
    DefinitionMetadata::setVariantsBy($variants, 'kind');
    DefinitionMetadata::setVariantsFrom($variants, 'surface_examples');
    DefinitionMetadata::setReadVariants($variants);
    $cases['variants by a key, from a provider, read'] = $variants;
    $each = MapDataDefinition::create()->setLabel('Per entry');
    DefinitionMetadata::setEach($each, DataDefinition::create('string')->setLabel('Entry'));
    $cases['a map of entries from the site'] = $each;
    $shaped = DataDefinition::create('string')->setLabel('Phone');
    DefinitionMetadata::setShape($shaped, 'hand.phone', DataDefinition::create('string')->setLabel('Phone'));
    $cases['a shape'] = $shaped;
    $typed = MapDataDefinition::create()->setLabel('Typed')->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    DefinitionMetadata::setConfigType($typed, 'hand.typed', MapDataDefinition::create()->setPropertyDefinition('a', DataDefinition::create('string')));
    $cases['a config type'] = $typed;
    return $cases;
  }

}
