<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\TypedData\FieldItemDataDefinition;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionTypedData;
use Drupal\surface\Plugin\SurfaceWidget\StringWidget;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resolve;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\PlainTextMarkup;
use Drupal\surface\Variants;
use Drupal\surface_values_test\HandBuiltSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a definition read into a schema and derived back is the definition.
 *
 * The rule: for a definition `$d` of the three classes the inverse
 * derives, DefinitionFromSchema::of(DefinitionSchema::of($d)) equals `$d` by
 * DefinitionEqualityTrait's rules (class, toArray() as a map, the getters,
 * properties, item, every DefinitionMetadata getter; objects by
 * identity), it is a new object at every depth, and the schema read from it
 * equals the schema it was derived from; at every depth the schema's merged
 * constraint list (SchemaFacts::constraints(): the `constraints` member
 * where the schema holds one, else derived) is getConstraints(). A schema
 * serialized and unserialized derives a definition equal to `$d` with
 * objects equal by class and content. A definition of any other class,
 * or of a type core's typed data manager does not define as it was read,
 * is refused.
 *
 * Walked: every data surface of the 19 example modules and of
 * `surface_values_test` (its declarations and HandBuiltSurfaces), declared
 * and refined by each of its value sets (ExampleValueSetsTrait), and every
 * definition reachable from an entry: its own, a variants' shell and each
 * variant's child, an attachment's child, a collection's item child, a
 * structure an alter mounted, and below each, a property, an item, a
 * shape's, a config type's and `each`'s definition. The counts are pinned
 * as literals, so a walk that finds nothing fails, and a member no
 * example states shows as 0. Then one hand case for each fact the
 * examples do not state.
 *
 * Decision: see
 * docs/decisions.md#a-schema-holds-every-fact-of-its-definition-and-a-definition-is-derived-back-from-it.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefinitionRoundTripTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;
  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
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
   * What the walk finds.
   *
   * Data surfaces (declared and refined, children included), definitions
   * an entry holds that are compared (each distinct object once), and the
   * distinct definitions reachable at every depth. A sealed key that
   * holds a child frame derives its definition with copies of its child's
   * definitions, which are not reached a second time (reach()). Since
   * the seal makes every entry schema-first (2026-11-15) one more object is
   * reached than the 1,097 before: example 3's compliance mount's license
   * key, held by a refined copy of the mount's derived definition beside
   * the structure's own entry. It is the same key. Since a refinement
   * composes a refined attachment's or chosen variants' schema and the
   * entry is schema-first (2026-11-16, part 32.8), 101 fewer are reached,
   * 997: the definitions of the keys a refined child refined, which only
   * the refined shell's definition held before; the shell's definition is
   * now derived from its schema, with copies, which reach() does not walk,
   * as for any schema-first shell. Every definition compared is compared
   * still. Since the document is written from schemas (2026-11-16), 2
   * more are compared and 10 more reached, 795 and 1,007: the value sets
   * are read from served documents, whose emitter derived a definition
   * for each key it described; a refinement that keeps a key's source
   * then held that very definition. Now each entry derives its own when
   * this walk asks. With every sealed entry's definition derived before
   * the refinements, as the emitter did, the counts are 793 and 997 again.
   */
  protected const COUNTS = [
    'surfaces' => 327,
    'compared' => 795,
    'reachable' => 1007,
  ];

  /**
   * The distinct definitions reachable, by class.
   *
   * 705, 43 and 350 before 2026-11-16: the 101 fewer of COUNTS. 647, 39
   * and 311 until the document was written from schemas (2026-11-16): the
   * 10 more of COUNTS.
   */
  protected const CLASSES = [
    DataDefinition::class => 653,
    ListDataDefinition::class => 39,
    MapDataDefinition::class => 315,
  ];

  /**
   * The distinct definitions reachable, by data type.
   *
   * Before 2026-11-16 (the 101 fewer of COUNTS): any 47, boolean 64,
   * email 28, integer 122, list 43, map 350, string 404. Until the
   * document was written from schemas (2026-11-16, the 10 more of
   * COUNTS): integer 114, map 311, string 391.
   */
  protected const TYPES = [
    'any' => 31,
    'boolean' => 58,
    'datetime_iso8601' => 7,
    'decimal' => 1,
    'email' => 13,
    'float' => 14,
    'integer' => 116,
    'list' => 39,
    'map' => 315,
    'string' => 395,
    'timestamp' => 5,
    'uri' => 13,
  ];

  /**
   * How often each member is on a schema reached, at every depth.
   *
   * `constraints` is 1 (1,105 before part 32.5): the merged list is derived
   * wherever the schema's other members say it (SchemaFacts::derived()), and
   * the one schema that holds it is HandBuiltSurfaces' `decimal`, a type
   * with no row in LeafTypes::DEFAULT_CONSTRAINTS. Until the document was
   * written from schemas (2026-11-16, the 10 more of COUNTS): type 1,625,
   * required 409, declared 579, grouping 30, placedAfter 15, shape 44.
   */
  protected const MEMBERS = [
    'type' => 1635,
    'definitionClass' => 49,
    'label' => 1,
    'description' => 98,
    'required' => 411,
    'readOnly' => 0,
    'computed' => 0,
    'settings' => 101,
    'constraints' => 1,
    'declared' => 583,
    'default' => 12,
    'examples' => 0,
    'secret' => 0,
    'variantsBy' => 105,
    'heldWhole' => 0,
    'readVariants' => 12,
    'oneVariant' => 0,
    'fixed' => 0,
    'grouping' => 32,
    'alwaysHeld' => 164,
    'placedAfter' => 17,
    'variantsFrom' => 116,
    'ownLabel' => 1,
    'arrangement' => 46,
    'compute' => 5,
    'resolve' => 3,
    'shape' => 46,
    'configType' => 4,
    'each' => 4,
    'doesNotApply' => 41,
    'mainProperty' => 0,
    'stored' => 0,
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
   * Tests every definition of every example surface round trips.
   */
  public function testEveryExampleDefinitionRoundTrips(): void {
    $surfaces = 0;
    /** @var \SplObjectStorage<\Drupal\surface\SurfaceInterface, true> $walked */
    $walked = new \SplObjectStorage();
    /** @var \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, string> $tops */
    $tops = new \SplObjectStorage();
    /** @var \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, true> $derivedShells */
    $derivedShells = new \SplObjectStorage();
    $sources = [];
    foreach ($this->examples(FALSE, [...self::WALKED, 'surface_values_test']) as [$id, $operation, $surface]) {
      $sources[$id . ($operation === NULL ? '' : ':' . $operation)] = $surface;
    }
    $sources[HandBuiltSurfaces::ID] = HandBuiltSurfaces::surface();
    $sources[HandBuiltSurfaces::UNWRITTEN_ID] = HandBuiltSurfaces::unwritten();
    foreach ($sources as $name => $surface) {
      $refined = ["$name declared" => $surface];
      // The value sets are read from a served document, which refuses the
      // types no row is for: that surface is walked as declared.
      $sets = $name === HandBuiltSurfaces::UNWRITTEN_ID ? [] : $this->valueSets($surface);
      foreach ($sets as $set => $values) {
        $refined["$name [$set]"] = $surface->refine($values);
      }
      foreach ($refined as $at => $each) {
        $surfaces += static::surfacesIn($each, $at, $walked, $tops, $derivedShells);
      }
    }

    /** @var \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, null> $reachable */
    $reachable = new \SplObjectStorage();
    $differ = [];
    $members = array_fill_keys(DefinitionSchema::MEMBERS, 0);
    foreach ($tops as $definition) {
      $at = (string) $tops[$definition];
      static::reach($definition, $reachable, $derivedShells->contains($definition));
      array_push($differ, ...$this->roundTrip($definition, $at, $members));
    }
    $classes = [];
    $types = [];
    foreach ($reachable as $definition) {
      $classes[get_class($definition)] = ($classes[get_class($definition)] ?? 0) + 1;
      $types[$definition->getDataType()] = ($types[$definition->getDataType()] ?? 0) + 1;
    }
    ksort($classes);
    ksort($types);
    $this->assertSame([], array_slice($differ, 0, 40), count($differ) . ' differences.');
    $this->assertSame(self::COUNTS, [
      'surfaces' => $surfaces,
      'compared' => count($tops),
      'reachable' => count($reachable),
    ]);
    $this->assertSame(self::CLASSES, $classes, 'The definitions reached, by class.');
    $this->assertSame(self::TYPES, $types, 'The definitions reached, by data type.');
    $this->assertSame(self::MEMBERS, $members, 'How often each member is stated.');
  }

  /**
   * Tests a hand case for each fact the examples do not state.
   *
   * Then what is no fact: two definitions whose arrays differ only in the
   * order of their keys, and one with no `type` key beside one whose
   * `type` key holds its data type, read into equal schemas.
   */
  public function testHandCases(): void {
    $members = array_fill_keys(DefinitionSchema::MEMBERS, 0);
    foreach ($this->handCases() as $case => [$definition, $stated]) {
      $differ = $this->roundTrip($definition, $case, $members);
      $this->assertSame([], $differ, $case);
      $model = DefinitionSchema::model(DefinitionSchema::of($definition));
      foreach ($stated as $member) {
        $this->assertArrayHasKey($member, $model, "$case: the schema holds $member.");
      }
    }
    foreach (DefinitionSchema::MEMBERS as $member) {
      $this->assertGreaterThan(0, $members[$member], "A hand case states $member.");
    }
    $pairs = [
      'keys in another order' => [
        new DataDefinition(['type' => 'string', 'label' => 'First', 'required' => TRUE]),
        new DataDefinition(['required' => TRUE, 'label' => 'First', 'type' => 'string']),
      ],
      'no type key' => [
        new DataDefinition(['label' => 'Untyped']),
        new DataDefinition(['type' => 'any', 'label' => 'Untyped']),
      ],
    ];
    foreach ($pairs as $case => [$one, $other]) {
      $this->assertSame([], static::schemaDifferences(DefinitionSchema::of($one), DefinitionSchema::of($other), $case), "$case: the two read into equal schemas.");
    }
  }

  /**
   * Tests what no schema can derive.
   *
   * A definition of any class but the three is refused with a
   * LogicException that names its class: a reference whose target was
   * set, a field item, an entity, and a subclass of each of the three. A
   * definition of a type core's typed data manager does not define (a
   * typed config `label`), or defines with other constraints (a typed
   * config `text`, which the tool module defines as a typed data type of
   * its own), is refused with a LogicException that names the type. A
   * list with no item definition cannot be read: core's getConstraints()
   * asks its item's data type.
   */
  public function testWhatCannotBeDerived(): void {
    $map = new class(['type' => 'map']) extends MapDataDefinition {};
    $map->setPropertyDefinition('a', DataDefinition::create('string'));
    $refused = [
      'a reference whose target was set' => DataReferenceDefinition::create('entity')->setLabel('Author')->setTargetDefinition(EntityDataDefinition::create('user')),
      'a field item' => FieldItemDataDefinition::create(BaseFieldDefinition::create('string')),
      'an entity' => EntityDataDefinition::create('user'),
      'a subclass of DataDefinition' => new class(['type' => 'string']) extends DataDefinition {},
      'a subclass of ListDataDefinition' => new class([], DataDefinition::create('string')) extends ListDataDefinition {},
      'a subclass of MapDataDefinition' => $map,
    ];
    foreach ($refused as $case => $definition) {
      $this->assertRefused($definition, get_class($definition), $case);
    }
    // As the typed config manager builds them for a config object's key:
    // a `label`, and a `text` that states constraints of its own, as a schema
    // type's description does, so the text type's own come from the typed
    // config manager.
    $typed = $this->container->get('config.typed');
    $keys = [
      'label' => ['type' => 'label'],
      'text' => ['type' => 'text', 'constraints' => ['NotBlank' => ['allowNull' => TRUE]]],
    ];
    foreach ($keys as $type => $key) {
      $definition = $typed->buildDataDefinition($key, NULL, 'key');
      $this->assertSame(DataDefinition::class, get_class($definition));
      $this->assertRefused($definition, "the $type type", "a typed config $type");
    }
    $this->expectException(\Error::class);
    DefinitionSchema::of(new ListDataDefinition(['type' => 'list']));
  }

  /**
   * Asserts the inverse refuses the schema of a definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, read into its schema.
   * @param string $named
   *   What the refusal names: the class or the type.
   * @param string $case
   *   The case, for the message.
   */
  protected function assertRefused(DataDefinitionInterface $definition, string $named, string $case): void {
    $schema = DefinitionSchema::of($definition);
    try {
      DefinitionFromSchema::of($schema);
      $this->fail("$case: refused.");
    }
    catch (\LogicException $refusal) {
      $this->assertSame(\LogicException::class, get_class($refusal), $case);
      $this->assertStringContainsString($named, $refusal->getMessage(), $case);
    }
  }

  /**
   * Tests a derived definition holds the objects the schema holds.
   *
   * The inverse makes a new definition wherever the schema holds a schema;
   * every other value is the object the schema holds, which is the object
   * the definition read holds, so nothing is cloned: a Computed, an object
   * in a setting and a definition in a setting are each the same object in
   * the definition, the schema and the derived definition, and a write
   * through the derived definition's setting is a write to the original's.
   */
  public function testDerivedDefinitionsHoldTheSchemasObjects(): void {
    $computed = new Computed(['name'], MachineName::class);
    $object = new \ArrayObject(['kept' => TRUE]);
    $inside = DataDefinition::create('string')->setLabel('Held in a setting');
    $definition = DataDefinition::create('string')->setLabel('Holds objects')
      ->setSetting('object', $object)
      ->setSetting('definition', $inside);
    DefinitionMetadata::setComputed($definition, $computed);
    $schema = DefinitionSchema::of($definition);
    $model = DefinitionSchema::model($schema);
    $derived = DefinitionFromSchema::of($schema);
    $this->assertInstanceOf(DataDefinition::class, $derived);
    $this->assertNotSame($definition, $derived);
    $this->assertSame($computed, $model['compute']);
    $this->assertSame($computed, Computed::of($derived));
    foreach (['object' => $object, 'definition' => $inside] as $setting => $held) {
      $this->assertSame($held, $model['settings'][$setting], "The schema holds the $setting in the setting.");
      $this->assertSame($held, $derived->getSetting($setting), "The derived definition holds the $setting in the setting.");
    }
    $through = $derived->getSetting('definition');
    $this->assertInstanceOf(DataDefinition::class, $through);
    $through->setLabel('Changed through the derived');
    $this->assertSame('Changed through the derived', $inside->getLabel());
  }

  /**
   * Checks one definition: the round trip, serialized, and its schema again.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $at
   *   Where, for the message.
   * @param array<string, int> $members
   *   How often each member is stated; gains this definition's schemas.
   *
   * @return list<string>
   *   Each difference.
   */
  protected function roundTrip(DataDefinitionInterface $definition, string $at, array &$members): array {
    $schema = DefinitionSchema::of($definition);
    static::countMembers($schema, $members);
    $differ = static::constraintsDifferences($definition, $schema, $at);
    try {
      $derived = DefinitionFromSchema::of($schema);
    }
    catch (\LogicException $refusal) {
      return [...$differ, "$at: not derived: " . $refusal->getMessage()];
    }
    array_push($differ, ...static::definitionDifferences($definition, $derived, $at));
    array_push($differ, ...static::schemaDifferences($schema, DefinitionSchema::of($derived), "$at, read again"));
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $copy = unserialize(serialize($schema));
    $this->assertInstanceOf(Schema::class, $copy);
    array_push($differ, ...static::definitionDifferences($definition, DefinitionFromSchema::of($copy), "$at, serialized", FALSE));
    return $differ;
  }

  /**
   * Lists where a schema's merged constraint list is not getConstraints().
   *
   * At every depth: the item, each property, and the definitions a shape,
   * a config type and `each` hold, beside the schemas their members hold.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition read.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   * @param string $at
   *   Where, for the message.
   *
   * @return list<string>
   *   Each place where SchemaFacts::constraints() is not identical to
   *   getConstraints().
   */
  protected static function constraintsDifferences(DataDefinitionInterface $definition, Schema $schema, string $at): array {
    $model = DefinitionSchema::model($schema);
    $differ = [];
    if (SchemaFacts::constraints($schema) !== $definition->getConstraints()) {
      $differ[] = "$at: the merged constraint list is not getConstraints()";
    }
    $pairs = [];
    if ($definition instanceof ListDataDefinitionInterface) {
      $pairs['/items'] = [$definition->getItemDefinition(), $schema->get('items')];
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface) {
      $properties = $schema->get('properties');
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $pairs["/properties/$name"] = [$property, is_array($properties) ? ($properties[$name] ?? NULL) : NULL];
      }
    }
    $pairs['/shape'] = [
      DefinitionMetadata::shapeDefinition($definition),
      $model['shape']['schema'] ?? NULL,
    ];
    $pairs['/configType'] = [
      DefinitionMetadata::configTypeDefinition($definition),
      $model['configType']['schema'] ?? NULL,
    ];
    $pairs['/each'] = [DefinitionMetadata::eachOf($definition), $model['each'] ?? NULL];
    foreach ($pairs as $where => [$below, $held]) {
      if ($below instanceof DataDefinitionInterface && $held instanceof Schema) {
        array_push($differ, ...static::constraintsDifferences($below, $held, $at . $where));
      }
    }
    return $differ;
  }

  /**
   * Walks a data surface and every child surface it holds.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $at
   *   Its name.
   * @param \SplObjectStorage<\Drupal\surface\SurfaceInterface, true> $walked
   *   The surfaces walked.
   * @param \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, string> $tops
   *   The definitions entries hold, each with where it was first found.
   * @param \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, true> $derivedShells
   *   The definitions a schema-first key that holds an attached child, a
   *   chosen variant or a collection derived: each holds its own copies of
   *   the child's definitions, which the child's own entries hold too.
   *
   * @return int
   *   The surfaces walked here, not walked before.
   */
  protected static function surfacesIn(SurfaceInterface $surface, string $at, \SplObjectStorage $walked, \SplObjectStorage $tops, \SplObjectStorage $derivedShells): int {
    if ($walked->contains($surface)) {
      return 0;
    }
    $walked->attach($surface, TRUE);
    $count = 1;
    foreach (['in' => $surface->getDefinitions(), 'out' => $surface->getOutputDefinitions()] as $side => $map) {
      foreach ($map->entries() as $name => $entry) {
        $here = "$at $side:$name";
        if (!$tops->contains($entry->definition())) {
          $tops->attach($entry->definition(), $here);
        }
        if ($entry->isSchemaFirst() && $entry->isShell() && $entry->mounted === []) {
          $derivedShells->attach($entry->definition(), TRUE);
        }
        $children = [];
        if ($entry->variants !== NULL) {
          if (!$tops->contains($entry->variants->shell)) {
            $tops->attach($entry->variants->shell, "$here shell");
          }
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
          $count += static::surfacesIn($child, "$here $what", $walked, $tops, $derivedShells);
        }
      }
    }
    return $count;
  }

  /**
   * Gathers every definition reachable from one, at every depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, null> $reachable
   *   The definitions so far.
   * @param bool $shell
   *   Whether it is the definition a schema-first key that holds a child
   *   frame derived (not a mount): its own map, or its list and the list's
   *   item map, is reached, and not the child's keys in it. Those are the
   *   child's entries' keys, reached from the child's own entries; the
   *   derived copies would count each key twice. A definition-first shell
   *   holds the child's entries' very definitions, so the count is the
   *   same in either form.
   */
  protected static function reach(DataDefinitionInterface $definition, \SplObjectStorage $reachable, bool $shell = FALSE): void {
    if ($reachable->contains($definition)) {
      return;
    }
    $reachable->attach($definition);
    $below = [
      DefinitionMetadata::shapeDefinition($definition),
      DefinitionMetadata::configTypeDefinition($definition),
      DefinitionMetadata::eachOf($definition),
    ];
    if ($definition instanceof ListDataDefinitionInterface) {
      if ($shell) {
        static::reach($definition->getItemDefinition(), $reachable, TRUE);
      }
      else {
        $below[] = $definition->getItemDefinition();
      }
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface && !$shell) {
      array_push($below, ...array_values($definition->getPropertyDefinitions()));
    }
    foreach ($below as $next) {
      if ($next instanceof DataDefinitionInterface) {
        static::reach($next, $reachable);
      }
    }
  }

  /**
   * Counts the members a schema and every schema inside it state.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   * @param array<string, int> $members
   *   The counts, by member.
   */
  protected static function countMembers(Schema $schema, array &$members): void {
    $model = DefinitionSchema::model($schema);
    foreach (array_keys($model) as $member) {
      $members[$member] = ($members[$member] ?? 0) + 1;
    }
    $inside = [
      $schema->get('items'),
      ...array_values(is_array($schema->get('properties')) ? $schema->get('properties') : []),
      $model['shape']['schema'] ?? NULL,
      $model['configType']['schema'] ?? NULL,
      $model['each'] ?? NULL,
    ];
    foreach ($inside as $next) {
      if ($next instanceof Schema) {
        static::countMembers($next, $members);
      }
    }
  }

  /**
   * Builds one definition for each fact the examples do not state.
   *
   * @return array<string, array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: list<string>}>
   *   Each case: the definition, and the members its schema states.
   */
  protected function handCases(): array {
    $cases = [];
    $cases['a label of \'\''] = [DataDefinition::create('string')->setLabel(''), ['label']];
    $cases['a label of NULL'] = [new DataDefinition(['type' => 'string', 'label' => NULL]), ['label']];
    $blank = DataDefinition::create('string')->setLabel('Code')->setDescription('');
    $cases['a description of \'\''] = [$blank, ['description']];

    $null = DataDefinition::create('string')->setLabel('None');
    DefinitionMetadata::setDefaultValue($null, NULL);
    $cases['an explicit NULL default'] = [$null, ['default']];

    $map = MapDataDefinition::create()->setLabel('Map')
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'));
    DefinitionMetadata::setDefaultValue($map, ['a' => 'own']);
    DefinitionMetadata::setExamples($map, [['a' => 'x']]);
    $cases['a map\'s own default and examples'] = [$map, ['default', 'examples']];

    $secret = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($secret);
    DefinitionMetadata::setDefaultValue($secret, 'hidden');
    DefinitionMetadata::setExamples($secret, ['one']);
    $cases['a secret with a default and examples'] = [$secret, ['default', 'examples']];

    $required = DataDefinition::create('string')->setLabel('Key')->setRequired(TRUE);
    DefinitionMetadata::setSecret($required);
    $cases['a required secret'] = [$required, ['required']];

    $not = DataDefinition::create('string')->setLabel('Plain');
    DefinitionMetadata::setSecret($not, FALSE);
    $cases['secret stored as FALSE'] = [$not, ['secret']];

    $held = DataDefinition::create('string')->setLabel('Held');
    DefinitionMetadata::setAlwaysHeld($held);
    $cases['an always held key'] = [$held, ['alwaysHeld']];

    $cases['integer'] = [DataDefinition::create('integer')->setLabel('Count'), ['type']];
    $cases['timestamp'] = [DataDefinition::create('timestamp')->setLabel('When'), ['type']];
    $cases['decimal'] = [DataDefinition::create('decimal')->setLabel('Amount'), ['type']];
    $cases['a bare any'] = [DataDefinition::create('any')->setLabel('Anything'), ['type']];
    $cases['binary'] = [DataDefinition::create('binary')->setLabel('Bytes'), ['type']];

    $marked = MapDataDefinition::create()->setLabel('Marked')
      ->setPropertyDefinition('kept', DataDefinition::create('string')->setLabel('Kept'))
      ->setPropertyDefinition('gone', DoesNotApply::marked(DataDefinition::create('string')->setLabel('Gone')));
    $cases['a marked property'] = [$marked, []];
    $gone = DoesNotApply::marked(DataDefinition::create('string')->setLabel('Gone'));
    $cases['a marked top-level key'] = [$gone, ['doesNotApply']];

    $cases['an empty MapDataDefinition'] = [MapDataDefinition::create()->setLabel('Empty'), ['definitionClass']];
    $cases['a DataDefinition of type map'] = [DataDefinition::create('map')->setLabel('Plain map'), []];

    $main = MapDataDefinition::create()->setLabel('Main')
      ->setPropertyDefinition('value', DataDefinition::create('string')->setLabel('Value'))
      ->setMainPropertyName('value');
    $cases['a map with a main property'] = [$main, ['mainProperty']];

    $each = MapDataDefinition::create()->setLabel('From the site');
    DefinitionMetadata::setEach($each, 'Drupal\surface_examples\Resource\Venue');
    $cases['each as a string'] = [$each, ['each']];
    $each = MapDataDefinition::create()->setLabel('From the site');
    DefinitionMetadata::setEach($each, DataDefinition::create('string')->setLabel('Entry')->addConstraint('Length', ['max' => 9]));
    $cases['each as a definition'] = [$each, ['each']];

    $shape = DataDefinition::create('string')->setLabel('Phone')->addConstraint('Length', ['max' => 20]);
    $shaped = DataDefinition::create('string')->setLabel('Phone')->addConstraint('Length', ['max' => 20]);
    DefinitionMetadata::setShape($shaped, 'phone_number', $shape);
    $cases['a shape'] = [$shaped, ['shape']];

    $declared = DataDefinition::create('string')->addConstraint('Regex', ['pattern' => '/^[a-z_]+$/D']);
    $typed = DataDefinition::create('string')->addConstraint('Regex', ['pattern' => '/^[a-z_]+$/D']);
    DefinitionMetadata::setConfigType($typed, 'machine_name', $declared);
    $cases['a config type'] = [$typed, ['configType']];

    $core = DataDefinition::create('string')->setLabel('Core')->setReadOnly(TRUE)->setComputed(TRUE);
    $cases['core\'s read-only and computed flags'] = [$core, ['readOnly', 'computed']];
    $classed = DataDefinition::create('string')->setLabel('Classed')
      ->setClass('Drupal\Core\TypedData\Plugin\DataType\StringData');
    $cases['a class key set by hand'] = [$classed, ['stored']];
    $foreign = DataDefinition::create('string')->setLabel('Foreign');
    $foreign['foreign'] = ['kept' => TRUE];
    $foreign->setInternal(TRUE);
    $cases['a foreign key and core\'s internal flag'] = [$foreign, ['stored']];
    $later = new DataDefinition(['label' => 'First', 'required' => TRUE, 'type' => 'string']);
    $cases['keys in another order'] = [$later, ['required']];
    $cases['no type key'] = [new DataDefinition(['label' => 'Untyped']), []];
    $empty = new DataDefinition(['type' => '', 'label' => 'Empty type']);
    $cases['a type key that is no data type'] = [$empty, ['stored']];

    $settings = DataDefinition::create('string')->setLabel('Settings')->setSettings([
      'widget' => 'date',
      'multiline' => TRUE,
      StringWidget::SETTINGS[0] => 20,
      StringWidget::SETTINGS[1] => 3,
      Variants::STORED_AS => 'flat',
      References::CONTENT => ['node'],
      References::TARGETS => ['article'],
      References::STORED_AS => 'uuid',
      DefinitionTypedData::DIGEST_SETTING => 'abc',
      'own_setting' => ['nested' => [1, 2]],
    ]);
    $cases['settings with every key the module writes'] = [$settings, ['settings']];

    $twice = DataDefinition::create('string')->setLabel('Twice')->setRequired(TRUE)
      ->addConstraint('NotNull', [])
      ->addConstraint('Length', ['max' => 3]);
    $cases['a constraint named twice'] = [$twice, ['declared']];
    $notBlank = DataDefinition::create('string')->setLabel('Blank')->setRequired(TRUE)->addConstraint('NotBlank', []);
    $cases['NotBlank on a required key'] = [$notBlank, ['declared', 'required']];

    $plural = DataDefinition::create('string')->setLabel(new PluralTranslatableMarkup(2, 'One item', '@count items'));
    $cases['a PluralTranslatableMarkup label'] = [$plural, []];
    $plain = DataDefinition::create('string')->setLabel(PlainTextMarkup::translatable(new TranslatableMarkup('Plain')));
    $cases['a PlainTextMarkup label'] = [$plain, []];
    $cases['a plain string label'] = [DataDefinition::create('string')->setLabel('Plain string'), []];

    $all = DataDefinition::create('any')->setLabel(new TranslatableMarkup('Everything'));
    DefinitionMetadata::setVariantsBy($all, 'kind');
    DefinitionMetadata::setHeldWhole($all);
    DefinitionMetadata::setReadVariants($all);
    DefinitionMetadata::setOneVariant($all);
    DefinitionMetadata::setFixed($all);
    DefinitionMetadata::setGrouping($all);
    DefinitionMetadata::setPlacedAfter($all, 'name');
    DefinitionMetadata::setVariantsFrom($all, 'Drupal\surface\Resource\Provider');
    DefinitionMetadata::keepOwnLabel($all);
    $all->setLabel('Reworded');
    $arrangement = new Arrangement(NULL, 5, new TranslatableMarkup('Type here'), 'pre', 'post', new TranslatableMarkup('Help'));
    DefinitionMetadata::setArrangement($all, $arrangement);
    DefinitionMetadata::setComputed($all, new Computed(['name'], MachineName::class));
    DefinitionMetadata::setResolve($all, new Resolve(['name' => 'stored.name'], MachineName::class, 'id'));
    $cases['every fact DefinitionMetadata stores'] = [
      $all,
      [
        'variantsBy',
        'heldWhole',
        'readVariants',
        'oneVariant',
        'fixed',
        'grouping',
        'placedAfter',
        'variantsFrom',
        'ownLabel',
        'arrangement',
        'compute',
        'resolve',
      ],
    ];

    $cases['a list'] = [ListDataDefinition::create('string')->setLabel('Tags'), []];
    return $cases;
  }

}
