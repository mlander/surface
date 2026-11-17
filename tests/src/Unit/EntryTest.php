<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\DefinitionMap;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Entry;
use Drupal\Tests\surface\Kernel\DefinitionEqualityTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests the single answer a data surface gives about one of its keys.
 *
 * The entry exists so that a key's definition, its owner, its locked
 * state, its refinement edges, its contributed values and its refiner
 * chains can never disagree with one another. Refinement is the only
 * thing that changes an entry, and it changes exactly one of those
 * seven things.
 *
 * An entry holds its definition's built node: read on the first
 * schema() call, the same object after, handed on to an entry with the
 * same definition, never serialized, and refused at schema(), never at
 * construction, for a definition that holds itself.
 *
 * A node-first entry (Entry::ofSchema()) holds the node its source gave
 * and derives its definition on the first definition() call: the same
 * object after, kept or dropped by the copies as their source says, only
 * the node serialized, refused at definition() with the key's name for a
 * node no definition derives from, and asserted not stale either way.
 *
 * @see \Drupal\surface\Entry
 * @see docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone
 * @see docs/decisions.md#an-entry-holds-the-form-its-source-gave
 */
#[Group('surface')]
class EntryTest extends UnitTestCase {

  use DefinitionEqualityTrait;

  /**
   * Tests the defaults a key that declares nothing extra takes.
   */
  public function testDefaults(): void {
    $definition = DataDefinition::create('string');
    $entry = new Entry('title', $definition);

    $this->assertSame('title', $entry->name);
    $this->assertSame($definition, $entry->definition());
    $this->assertSame(SurfaceInterface::OWNER, $entry->contributor);
    $this->assertFalse($entry->locked);
    $this->assertSame([], $entry->dependencies);
    $this->assertSame([], $entry->refiners);
    $this->assertSame([], $entry->contributions);
  }

  /**
   * Tests that narrowing replaces the definition and nothing else.
   */
  public function testWithDefinitionCarriesEverythingElse(): void {
    $refiner = static::refiner();
    $entry = new Entry(
      name: 'variant',
      definition: DataDefinition::create('string'),
      contributor: 'other_module',
      locked: TRUE,
      dependencies: ['casing'],
      refiners: [SurfaceInterface::OWNER => [$refiner]],
      contributions: ['other_module' => ['ribbon']],
    );
    $narrowed = DataDefinition::create('string')->setLabel('Narrowed');

    $next = $entry->withDefinition($narrowed);

    $this->assertNotSame($entry, $next);
    $this->assertSame($narrowed, $next->definition());
    $this->assertSame('variant', $next->name);
    $this->assertSame('other_module', $next->contributor);
    $this->assertTrue($next->locked);
    $this->assertSame(['casing'], $next->dependencies);
    $this->assertSame([SurfaceInterface::OWNER => [$refiner]], $next->refiners);
    $this->assertSame(['other_module' => ['ribbon']], $next->contributions);
    // The entry it came from is untouched.
    $this->assertNotSame($narrowed, $entry->definition());
  }

  /**
   * Tests the three ways a provider counts as having touched a key.
   */
  public function testTouchedBy(): void {
    $owned = new Entry('owned', DataDefinition::create('string'));
    $this->assertTrue($owned->touchedBy(SurfaceInterface::OWNER));
    $this->assertFalse($owned->touchedBy('other_module'));

    $mounted = new Entry('mounted', DataDefinition::create('string'), contributor: 'other_module');
    $this->assertTrue($mounted->touchedBy('other_module'));

    $extended = new Entry(
      'extended',
      DataDefinition::create('string'),
      contributions: ['other_module' => ['ribbon']],
    );
    $this->assertTrue($extended->touchedBy('other_module'));

    $refined = new Entry(
      'refined',
      DataDefinition::create('string'),
      refiners: ['other_module' => [static::refiner()]],
    );
    $this->assertTrue($refined->touchedBy('other_module'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A definition's constraints ask the typed data manager for its type's
    // defaults: none here.
    $typed = $this->createMock(TypedDataManagerInterface::class);
    $typed->method('getDefaultConstraints')->willReturn([]);
    // A node-first entry derives its definition (NodeDefinition::of()),
    // which asks whether the type is defined, and a derived definition is
    // compared by its class, which asks the type's definition.
    $typed->method('hasDefinition')->willReturnCallback(static fn (string $type): bool => $type !== 'not_a_type');
    $typed->method('getDefinition')->willReturn(['class' => \stdClass::class]);
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $typed);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a map with two properties.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  protected static function map(): MapDataDefinition {
    return MapDataDefinition::create()->setLabel('Where')
      ->setPropertyDefinition('city', DataDefinition::create('string')->setLabel('City')->setRequired(TRUE))
      ->setPropertyDefinition('rooms', DataDefinition::create('integer')->setLabel('Rooms'));
  }

  /**
   * Writes a node as JSON, MODEL included.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return string
   *   Its JSON.
   */
  protected static function json(Schema $node): string {
    return (string) json_encode(Schemas::arrayed($node), JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  /**
   * Tests schema() is the read of the definition, read once.
   */
  public function testSchemaIsTheDefinitionsNode(): void {
    $definition = static::map();
    $entry = new Entry('where', $definition);
    $node = $entry->schema();
    $this->assertSame(static::json(DefinitionSchema::of($definition)), static::json($node), 'The node is the read of the definition.');
    $this->assertSame($node, $entry->schema(), 'A second call returns the same object.');
    $this->assertSame(['city', 'rooms'], array_keys($node->get('properties')), 'Every property is on it.');
  }

  /**
   * Tests a key that holds a child frame composes its node of the child's.
   */
  public function testShellComposesItsNode(): void {
    $children = static::children('City');
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn($children);
    $definition = Attachment::mapOf(MapDataDefinition::create()->setLabel('Where'), $child);
    $entry = new Entry('where', $definition, attachment: new Attachment($child));
    $node = $entry->schema();
    $this->assertSame(static::json(DefinitionSchema::of($definition)), static::json($node), 'The composed node is the read of the definition.');
    $this->assertSame($node, $entry->schema(), 'A second ask returns the same object.');
    foreach (['city', 'rooms'] as $name) {
      $this->assertSame($children->entry($name)?->schema(), DefinitionSchema::propertyNode($node, $name), "A child entry's node is the very object under properties.");
    }

    // A refinement composes the refined shell of the shell's own node and
    // the refined child's entries' nodes (Surface::refineNested()).
    $refined_children = static::children('Town');
    $refined = $this->createMock(SurfaceInterface::class);
    $refined->method('getDefinitions')->willReturn($refined_children);
    $narrowed = $entry->withSchema(DefinitionSchema::withProperties($node, static::nodes($refined_children)));
    $this->assertTrue($narrowed->isNodeFirst(), 'The refined shell is node-first.');
    $this->assertSame('Town', DefinitionSchema::propertyNode($narrowed->schema(), 'city')?->get('title'));
    $this->assertSame($refined_children->entry('city')?->schema(), DefinitionSchema::propertyNode($narrowed->schema(), 'city'), "The refined child's entry's node is the one under properties.");
    $this->assertSame(static::json(DefinitionSchema::of(Attachment::mapOf($definition, $refined))), static::json($narrowed->schema()), 'And it is the read of the definition the refinement built before.');
  }

  /**
   * Tests a refined shell keeps the nodes it was composed of, and only them.
   *
   * A copy bound to its container's values (readingFrom()) holds the
   * composed node; a refined mount no refined structure stands for reads
   * its keys, and takes nothing by name from the sealed structure.
   */
  public function testRefinedShellKeepsItsChild(): void {
    $children = static::children('City');
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn($children);
    $child->method('withPorts')->willReturnSelf();
    $definition = Attachment::mapOf(MapDataDefinition::create()->setLabel('Where'), $child);
    $entry = new Entry('where', $definition, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $refined_children = static::children('Town');
    $refined = $this->createMock(SurfaceInterface::class);
    $refined->method('getDefinitions')->willReturn($refined_children);
    $composed = $entry->withSchema(DefinitionSchema::withProperties($entry->schema(), static::nodes($refined_children)));
    $bound = $composed->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($bound);
    $this->assertSame($refined_children->entry('city')?->schema(), DefinitionSchema::propertyNode($bound->schema(), 'city'), 'A bound copy holds the composed node, the refined child\'s very node in it.');
    $this->assertTrue($bound->sameSource($composed), 'It has the refined shell\'s source.');
    $this->assertFalse($bound->sameSource($entry), 'Not the sealed one\'s.');

    // A mount: the module's map holds a deep copy of the structure's
    // definitions, as SurfaceBuilder::mountStructure() mounts them.
    $module = MapDataDefinition::create()->setLabel('Module');
    foreach ($children->entries() as $name => $child_entry) {
      $module->setPropertyDefinition((string) $name, Surface::deepClone($child_entry->definition()));
    }
    $mount = MapDataDefinition::create()->setLabel('Mounts')->setPropertyDefinition('module', $module);
    $sealed = new Entry('third_party_settings', $mount, mounted: ['module' => new Attachment($child)]);
    $city = $children->entry('city')?->schema();
    $this->assertSame($city, DefinitionSchema::propertyNode(DefinitionSchema::propertyNode($sealed->schema(), 'module') ?? $mount, 'city') ?? NULL, 'A sealed mount takes the structure\'s keys by name.');
    $copy = $sealed->withDefinition(Surface::deepClone($mount));
    $read = DefinitionSchema::propertyNode(DefinitionSchema::propertyNode($copy->schema(), 'module') ?? $mount, 'city');
    $this->assertNotSame($city, $read, 'A refined mount no refined structure stands for reads its keys.');
    $this->assertSame(static::json(DefinitionSchema::of($copy->definition())), static::json($copy->schema()), 'And it is the read of its definition.');
  }

  /**
   * Tests which entries have the same source.
   *
   * The same form and the very object of it: what the served contract
   * tells a key a refinement moved by (ContractEmitter::frame()).
   */
  public function testTheSameSource(): void {
    $node = DefinitionSchema::of(static::map());
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn(static::children('City'));
    $child->method('withPorts')->willReturnSelf();
    $sealed = Entry::ofSchema('where', $node, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $this->assertTrue($sealed->sameSource($sealed), 'An entry and itself.');
    $copy = $sealed->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($copy);
    $this->assertTrue($copy->sameSource($sealed), 'A port copy and its original.');
    $this->assertTrue($sealed->sameSource($copy), 'Its original and the port copy.');
    $copy->definition();
    $this->assertTrue($sealed->sameSource($copy), 'Whatever either derived since.');
    $mount = Entry::ofSchema('third_party_settings', $node, mounted: ['module' => new Attachment($child, reads: ['venue' => 'venue'])]);
    $mounted = $mount->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($mounted);
    $this->assertNotSame($mount, $mounted);
    $this->assertTrue($mounted->sameSource($mount), 'A port copy of a mount and its original.');
    $refined = $sealed->withSchema(DefinitionSchema::withProperties($node, static::nodes(static::children('City'))));
    $this->assertFalse($refined->sameSource($sealed), 'A refined shell and its sealed entry, even where the child changed nothing.');
    $this->assertTrue(DefinitionSchema::same($refined->schema(), DefinitionSchema::withProperties($node, static::nodes(static::children('City')))));
    $answer = Surface::deepClone($sealed->definition());
    $made = $sealed->withDefinition($answer);
    $this->assertFalse($made->sameSource($sealed), 'A refiner\'s answer and the sealed entry.');
    $this->assertTrue($sealed->withDefinition($sealed->definition())->sameSource($sealed), 'The derived definition handed back keeps the source.');
    $definition = static::map();
    $one = new Entry('where', $definition);
    $two = new Entry('where', $definition);
    $this->assertTrue($one->sameSource($two), 'Two definition-first entries of one definition object.');
    $this->assertFalse($one->sameSource(new Entry('where', static::map())), 'Not of an equal one.');
    $nodeFirst = Entry::ofSchema('where', $one->schema());
    $this->assertFalse($one->sameSource($nodeFirst), 'A definition-first entry and a node-first one of its node.');
    $this->assertFalse($nodeFirst->sameSource($one), 'Either way round.');
  }

  /**
   * Tests a port copy carries the mark that a refinement made its source.
   */
  public function testPortCopyCarriesTheRefinementMark(): void {
    $node = DefinitionSchema::of(static::map());
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn(static::children('City'));
    $sealed = Entry::ofSchema('where', $node, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $this->assertFalse($sealed->readingFrom(['venue' => 'hall'])?->madeByRefinement(), 'A copy of a sealed entry: no mark.');
    $composed = $sealed->withSchema(DefinitionSchema::withProperties($node, static::nodes(static::children('Town'))), TRUE);
    $this->assertTrue($composed->madeByRefinement());
    $this->assertTrue($composed->readingFrom(['venue' => 'hall'])?->madeByRefinement(), 'A copy of a shell a refinement composed carries it.');
    $answer = $sealed->withDefinition(Surface::deepClone($sealed->definition()));
    $this->assertTrue($answer->readingFrom(['venue' => 'hall'])?->madeByRefinement(), 'A copy of a refiner\'s answer carries it.');
  }

  /**
   * Gets a child's entries' nodes, by name.
   *
   * @param \Drupal\surface\DefinitionMap $entries
   *   The entries.
   *
   * @return array<string, \PortableSchema\Builder\Schema>
   *   Each entry's node.
   */
  protected static function nodes(DefinitionMap $entries): array {
    $nodes = [];
    foreach ($entries->entries() as $name => $entry) {
      $nodes[(string) $name] = $entry->schema();
    }
    return $nodes;
  }

  /**
   * Makes a child's entries: a required city with this label, and rooms.
   *
   * @param string $label
   *   The city's label.
   *
   * @return \Drupal\surface\DefinitionMap
   *   The entries.
   */
  protected static function children(string $label): DefinitionMap {
    return new DefinitionMap([
      new Entry('city', DataDefinition::create('string')->setLabel($label)->setRequired(TRUE)),
      new Entry('rooms', DataDefinition::create('integer')->setLabel('Rooms')),
    ]);
  }

  /**
   * Tests an entry made with the same definition keeps the node.
   */
  public function testTheNodeTravelsWithTheDefinition(): void {
    $definition = static::map();
    $entry = new Entry('where', $definition);
    $node = $entry->schema();

    $other = static::map()->setLabel('Elsewhere');
    $narrowed = $entry->withDefinition($other);
    $this->assertNotSame($node, $narrowed->schema(), 'Another definition starts unread.');
    $this->assertSame(static::json(DefinitionSchema::of($other)), static::json($narrowed->schema()), 'It reads that definition.');
    $this->assertSame('Elsewhere', $narrowed->schema()->get('title'));

    $this->assertSame($node, $entry->withDefinition($definition)->schema(), 'The same definition keeps the node.');

    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn(new DefinitionMap([]));
    $wired = new Entry('where', $definition, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $read = $wired->schema();
    $bound = $wired->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($bound);
    $this->assertNotSame($wired, $bound);
    $this->assertSame(['venue' => 'hall'], $bound->attachment?->ports);
    $this->assertSame($read, $bound->schema(), 'readingFrom() hands the node on.');
  }

  /**
   * Tests the node never enters a serialized entry.
   */
  public function testTheNodeIsNotSerialized(): void {
    $unread = new Entry('where', static::map());
    $read = new Entry('where', static::map());
    $node = $read->schema();
    $this->assertSame(serialize($unread), serialize($read), 'An entry whose node was read serializes as one never read.');
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, serialize($read));
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($read));
    $this->assertInstanceOf(Entry::class, $woken);
    $again = $woken->schema();
    $this->assertNotSame($node, $again, 'An unserialized entry reads its node again.');
    $this->assertSame(['title' => 'Where'], array_intersect_key(Schemas::arrayed($again), ['title' => TRUE]));
  }

  /**
   * Tests a definition written after its node was read fails the assertion.
   */
  public function testWrittenDefinitionFailsTheAssertion(): void {
    if ((int) ini_get('zend.assertions') !== 1) {
      $this->markTestSkipped('Assertions are off.');
    }
    $city = DataDefinition::create('string')->setLabel('City')->setRequired(TRUE);
    $rooms = DataDefinition::create('integer')->setLabel('Rooms');
    $definition = MapDataDefinition::create()->setLabel('Where')
      ->setPropertyDefinition('city', $city)
      ->setPropertyDefinition('rooms', $rooms);
    $entry = new Entry('where', $definition);
    $this->assertSame($entry->schema(), $entry->schema(), 'Nothing written: the later ask passes.');
    $writes = [
      'a label' => static fn () => $definition->setLabel('Changed'),
      'a property below' => static fn () => $city->setRequired(FALSE),
      'a property added' => static fn () => $definition->setPropertyDefinition('street', DataDefinition::create('string')),
      'a constraint' => static fn () => $rooms->addConstraint('Range', ['min' => 1]),
      'a setting' => static fn () => $definition->setSetting('kind', 'hall'),
    ];
    foreach ($writes as $what => $write) {
      $entry = new Entry('where', $definition);
      $entry->schema();
      $write();
      try {
        $entry->schema();
        $this->fail("$what written after the read is not seen.");
      }
      catch (\AssertionError $error) {
        $this->assertStringContainsString('was written after its node was read', $error->getMessage(), $what);
      }
    }
  }

  /**
   * Tests a place is what the key's schema declares, and nothing else.
   *
   * Since 2026-11-16 Location::below() walks the key's schema alone,
   * whichever form its source gave. A class that answers for a property
   * it does not list declares none there as far as its schema says (the
   * read never sees the property): the place is none. Until then the
   * definition was walked beside the schema for a definition-first entry,
   * and such a place was refused as out of step.
   */
  public function testLocationIsReadFromTheSchemaAlone(): void {
    // A map that answers for a property it does not list: the read never
    // sees it, so the node holds none for it.
    $map = new class(['type' => 'map']) extends MapDataDefinition {

      /**
       * {@inheritdoc}
       */
      public function getPropertyDefinition($name) {
        return $name === 'ghost' ? DataDefinition::create('string') : parent::getPropertyDefinition($name);
      }

    };
    $map->setPropertyDefinition('city', DataDefinition::create('string'));
    $entry = new Entry('where', $map);
    $below = new \ReflectionMethod(Location::class, 'below');
    $found = $below->invoke(NULL, $entry, ['city' => 'Ghent'], ['city']);
    $this->assertInstanceOf(Location::class, $found);
    $this->assertSame($entry->schema()->get('properties')['city'], $found->node, 'The node at a place is the one its parent node holds.');
    $this->assertNull($below->invoke(NULL, $entry, [], ['nothing']), 'A place nothing declares is none.');
    $this->assertNull($below->invoke(NULL, $entry, [], ['ghost']), 'A place only the class answers for is none: its schema declares nothing there.');
    // Below a list, a delta names its item and any other segment nothing.
    $tags = MapDataDefinition::create()->setPropertyDefinition('tags', new ListDataDefinition([], DataDefinition::create('string')));
    $listed = new Entry('listed', $tags);
    $item = $below->invoke(NULL, $listed, ['tags' => ['a']], ['tags', '0']);
    $this->assertInstanceOf(Location::class, $item);
    $this->assertSame($listed->schema()->get('properties')['tags']->get('items'), $item->node, 'A delta below a list is its item.');
    $this->assertSame('a', $item->value);
    $this->assertNull($below->invoke(NULL, $listed, ['tags' => ['a']], ['tags', 'x']), 'A name below a list is no place.');
  }

  /**
   * Tests a definition that holds itself is refused at schema(), not before.
   */
  public function testSelfHoldingDefinitionIsRefusedAtSchema(): void {
    $map = MapDataDefinition::create()->setLabel('Loop');
    $map->setPropertyDefinition('self', $map);
    $entry = new Entry('loop', $map);
    $this->assertSame($map, $entry->withDefinition($map)->definition(), 'Made, and made again, with nothing read.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('holds itself');
    $entry->schema();
  }

  /**
   * Tests a node-first entry answers with its node and a derived definition.
   */
  public function testNodeFirstEntryDerivesItsDefinition(): void {
    $definition = static::map();
    $node = DefinitionSchema::of($definition);
    $refiner = static::refiner();
    $entry = Entry::ofSchema('where', $node, contributor: 'other_module', locked: TRUE, dependencies: ['venue'], refiners: [SurfaceInterface::OWNER => [$refiner]]);
    $this->assertSame($node, $entry->schema(), 'schema() is the node given.');
    $this->assertSame($node, $entry->schema(), 'And the same on a second ask.');
    $derived = $entry->definition();
    $this->assertSame([], static::definitionDifferences($definition, $derived, 'where'), 'The derived definition equals the one the node was read from.');
    $this->assertSame($derived, $entry->definition(), 'A second ask returns the same object.');
    $this->assertSame('other_module', $entry->contributor, 'The other members are the ones handed.');
    $this->assertTrue($entry->locked);
    $this->assertSame(['venue'], $entry->dependencies);
    $this->assertSame([SurfaceInterface::OWNER => [$refiner]], $entry->refiners);
  }

  /**
   * Tests the copies keep the source, and the other form when it is the same.
   */
  public function testCopiesKeepTheSource(): void {
    $node = DefinitionSchema::of(static::map());
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('getDefinitions')->willReturn(new DefinitionMap([]));
    $entry = Entry::ofSchema('where', $node, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $derived = $entry->definition();

    $bound = $entry->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($bound);
    $this->assertSame($node, $bound->schema(), 'readingFrom() keeps the node given.');
    $this->assertSame($derived, $bound->definition(), 'And the definition derived from it.');
    $this->assertSame(serialize(Entry::ofSchema('where', $node, attachment: $bound->attachment)), serialize($bound), 'And it stays node-first.');

    $same = $entry->withDefinition($derived);
    $this->assertSame([$node, $derived], [$same->schema(), $same->definition()], 'withDefinition() handed the derived definition keeps both.');

    $other = static::map()->setLabel('Elsewhere');
    $narrowed = $entry->withDefinition($other);
    $this->assertSame($other, $narrowed->definition(), 'withDefinition() handed another definition is definition-first.');
    $this->assertNotSame($node, $narrowed->schema(), 'It starts unread.');
    $this->assertSame('Elsewhere', $narrowed->schema()->get('title'));

    $again = $entry->withSchema($node);
    $this->assertSame([$node, $derived], [$again->schema(), $again->definition()], 'withSchema() handed the node it holds keeps the derived definition.');
    $next = DefinitionSchema::of($other);
    $moved = $entry->withSchema($next);
    $this->assertSame($next, $moved->schema(), 'withSchema() handed another node holds it.');
    $this->assertNotSame($derived, $moved->definition(), 'And derives its definition anew.');
    $this->assertSame('Elsewhere', $moved->definition()->getLabel());

    $given = static::map();
    $read = new Entry('where', $given);
    $its = $read->schema();
    $turned = $read->withSchema($its);
    $this->assertSame([$its, $given], [$turned->schema(), $turned->definition()], 'A definition-first entry handed its own node keeps the definition, which reads as it.');
  }

  /**
   * Tests each kind serializes the form given, and answers both after.
   */
  public function testEachKindSerializesTheFormGiven(): void {
    $definition = static::map();
    $node = DefinitionSchema::of($definition);
    $entry = Entry::ofSchema('where', $node);
    $unread = serialize($entry);
    $entry->definition();
    $this->assertSame($unread, serialize($entry), 'A node-first entry serializes the same whether or not its definition was derived.');
    $this->assertStringContainsString(DefinitionSchema::MODEL, $unread, 'It serializes its node.');
    $this->assertStringNotContainsString('MapDataDefinition', $unread, 'And no definition.');
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize($unread);
    $this->assertInstanceOf(Entry::class, $woken);
    $this->assertSame(static::json($node), static::json($woken->schema()), 'Unserialized, it answers schema() with an equal node.');
    $this->assertSame([], static::definitionDifferences($definition, $woken->definition(), 'where', FALSE), 'And definition() with an equal definition.');
    $this->assertSame($woken->definition(), $woken->definition());

    $first = new Entry('where', static::map());
    $first->schema();
    $kept = serialize($first);
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, $kept, 'A definition-first entry serializes no node.');
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $back = unserialize($kept);
    $this->assertInstanceOf(Entry::class, $back);
    // Core drops a map's properties when it serializes the map, which a
    // data surface keeps beside itself (SerializedMaps); an entry alone
    // does not. A node keeps them.
    $this->assertSame('{"title":"Where","x-surface-model":{"type":"map","definitionClass":"Drupal\\\\Core\\\\TypedData\\\\MapDataDefinition"}}', static::json($back->schema()), 'Unserialized alone, a definition-first map reads with no properties.');
  }

  /**
   * Tests a node no definition derives from is refused at definition().
   */
  public function testNodeNoDefinitionDerivesFromIsRefused(): void {
    $node = DefinitionSchema::of(DataDefinition::create('not_a_type')->setLabel('Odd'));
    $entry = Entry::ofSchema('odd', $node);
    $this->assertSame($node, $entry->schema(), 'Made, with nothing derived.');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "odd" key\'s source gave a node, and no definition derives from it: No definition of the not_a_type type is derived');
    $entry->definition();
  }

  /**
   * Tests both staleness assertions, each with a planted write.
   */
  public function testStalenessIsAssertedEitherWay(): void {
    if ((int) ini_get('zend.assertions') !== 1) {
      $this->markTestSkipped('Assertions are off.');
    }
    $node = DefinitionSchema::of(static::map());
    $entry = Entry::ofSchema('where', $node);
    $derived = $entry->definition();
    $this->assertSame($derived, $entry->definition(), 'Nothing written: the later ask passes.');
    $this->assertInstanceOf(MapDataDefinition::class, $derived);
    $derived->setLabel('Written');
    try {
      $entry->definition();
      $this->fail('A derived definition written after it was derived is not seen.');
    }
    catch (\AssertionError $error) {
      $this->assertStringContainsString('derived from the node its source gave, was written after it was derived', $error->getMessage());
    }

    $definition = static::map();
    $first = new Entry('where', $definition);
    $first->schema();
    $definition->setLabel('Written');
    try {
      $first->schema();
      $this->fail('A definition written after its node was read is not seen.');
    }
    catch (\AssertionError $error) {
      $this->assertStringContainsString('its source, was written after its node was read', $error->getMessage());
    }
  }

  /**
   * Builds a refiner that narrows nothing.
   *
   * What an entry does with a refiner is carry it, so the only thing
   * asked of this one is that it is the right kind of object.
   *
   * @return \Drupal\surface\SurfaceRefinerInterface
   *   The refiner.
   */
  protected static function refiner(): SurfaceRefinerInterface {
    return new class() implements SurfaceRefinerInterface {

      /**
       * {@inheritdoc}
       */
      public function refine(string $name, Schema $key, array $values, string $who): array {
        return [];
      }

    };
  }

}
