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
use Drupal\surface\Entries;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Entry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests the single answer a data surface gives about one of its keys.
 *
 * The entry exists so that a key's schema, its declaring class, its locked
 * state, its refinement edges, its contributed values and its refiner chains
 * can never disagree with one another. Refinement is the only thing that
 * changes an entry, and it changes exactly one of those seven things.
 *
 * An entry holds its key's schema. An entry of a definition no schema
 * derives (Entry::ofHost()) keeps that definition and reads its schema on
 * the first schema() call: the same object after, refused there, never at
 * construction, for a definition that holds itself, and only the
 * definition serialized.
 *
 * @see \Drupal\surface\Entry
 * @see docs/decisions.md#an-entry-holds-a-schema-alone
 */
#[Group('surface')]
class EntryTest extends UnitTestCase {

  /**
   * Tests the defaults a key that declares nothing extra takes.
   */
  public function testDefaults(): void {
    $schema = Schema::string();
    $entry = new Entry('title', $schema);

    $this->assertSame('title', $entry->name);
    $this->assertSame($schema, $entry->schema());
    $this->assertNull($entry->host());
    $this->assertSame(SurfaceInterface::DECLARING_CLASS, $entry->contributor);
    $this->assertFalse($entry->locked);
    $this->assertSame([], $entry->dependencies);
    $this->assertSame([], $entry->refiners);
    $this->assertSame([], $entry->contributions);
  }

  /**
   * Tests that narrowing replaces the schema and nothing else.
   */
  public function testWithSchemaCarriesEverythingElse(): void {
    $refiner = static::refiner();
    $entry = new Entry(
      name: 'variant',
      schema: Schema::string(),
      contributor: 'other_module',
      locked: TRUE,
      dependencies: ['casing'],
      refiners: [SurfaceInterface::DECLARING_CLASS => [$refiner]],
      contributions: ['other_module' => ['ribbon']],
    );
    $narrowed = Schema::string()->title('Narrowed');

    $next = $entry->withSchema($narrowed);

    $this->assertNotSame($entry, $next);
    $this->assertSame($narrowed, $next->schema());
    $this->assertSame('variant', $next->name);
    $this->assertSame('other_module', $next->contributor);
    $this->assertTrue($next->locked);
    $this->assertSame(['casing'], $next->dependencies);
    $this->assertSame([SurfaceInterface::DECLARING_CLASS => [$refiner]], $next->refiners);
    $this->assertSame(['other_module' => ['ribbon']], $next->contributions);
    // The entry it came from is untouched.
    $this->assertNotSame($narrowed, $entry->schema());
  }

  /**
   * Tests the three ways a provider counts as having touched a key.
   */
  public function testTouchedBy(): void {
    $owned = new Entry('owned', Schema::string());
    $this->assertTrue($owned->touchedBy(SurfaceInterface::DECLARING_CLASS));
    $this->assertFalse($owned->touchedBy('other_module'));

    $mounted = new Entry('mounted', Schema::string(), contributor: 'other_module');
    $this->assertTrue($mounted->touchedBy('other_module'));

    $extended = new Entry(
      'extended',
      Schema::string(),
      contributions: ['other_module' => ['ribbon']],
    );
    $this->assertTrue($extended->touchedBy('other_module'));

    $refined = new Entry(
      'refined',
      Schema::string(),
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
    // A definition read asks whether the type is defined, and a definition
    // is compared by its class, which asks the type's definition.
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
   * Writes a schema as JSON, MODEL included.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return string
   *   Its JSON.
   */
  protected static function json(Schema $schema): string {
    return (string) json_encode(Schemas::arrayed($schema), JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  /**
   * Gets a child's entries' schemas, by name.
   *
   * @param \Drupal\surface\Entries $entries
   *   The entries.
   *
   * @return array<string, \PortableSchema\Builder\Schema>
   *   Each entry's schema.
   */
  protected static function schemas(Entries $entries): array {
    $schemas = [];
    foreach ($entries->entries() as $name => $entry) {
      $schemas[(string) $name] = $entry->schema();
    }
    return $schemas;
  }

  /**
   * Makes a child's entries: a required city with this label, and rooms.
   *
   * @param string $label
   *   The city's label.
   *
   * @return \Drupal\surface\Entries
   *   The entries.
   */
  protected static function children(string $label): Entries {
    return new Entries([
      new Entry('city', DefinitionSchema::of(DataDefinition::create('string')->setLabel($label)->setRequired(TRUE))),
      new Entry('rooms', DefinitionSchema::of(DataDefinition::create('integer')->setLabel('Rooms'))),
    ]);
  }

  /**
   * Tests a place is what the key's schema declares, and nothing else.
   *
   * Since 2026-11-16 Location::below() walks the key's schema alone, a
   * host entry's too. A class that answers for a property
   * it does not list declares none there as far as its schema says (the
   * read never sees the property): the place is none. Until then the
   * definition was walked beside the schema for a definition-first entry,
   * and such a place was refused as out of step.
   */
  public function testLocationIsReadFromTheSchemaAlone(): void {
    // A map that answers for a property it does not list: the read never
    // sees it, so the schema holds none for it.
    $map = new class(['type' => 'map']) extends MapDataDefinition {

      /**
       * {@inheritdoc}
       */
      public function getPropertyDefinition($name) {
        return $name === 'ghost' ? DataDefinition::create('string') : parent::getPropertyDefinition($name);
      }

    };
    $map->setPropertyDefinition('city', DataDefinition::create('string'));
    $entry = Entry::ofHost('where', $map);
    $below = new \ReflectionMethod(Location::class, 'below');
    $found = $below->invoke(NULL, $entry, ['city' => 'Ghent'], ['city']);
    $this->assertInstanceOf(Location::class, $found);
    $this->assertSame($entry->schema()->get('properties')['city'], $found->schema, 'The schema at a place is the one its parent schema holds.');
    $this->assertNull($below->invoke(NULL, $entry, [], ['nothing']), 'A place nothing declares is none.');
    $this->assertNull($below->invoke(NULL, $entry, [], ['ghost']), 'A place only the class answers for is none: its schema declares nothing there.');
    // Below a list, a delta names its item and any other segment nothing.
    $tags = MapDataDefinition::create()->setPropertyDefinition('tags', new ListDataDefinition([], DataDefinition::create('string')));
    $listed = new Entry('listed', DefinitionSchema::of($tags));
    $item = $below->invoke(NULL, $listed, ['tags' => ['a']], ['tags', '0']);
    $this->assertInstanceOf(Location::class, $item);
    $this->assertSame($listed->schema()->get('properties')['tags']->get('items'), $item->schema, 'A delta below a list is its item.');
    $this->assertSame('a', $item->value);
    $this->assertNull($below->invoke(NULL, $listed, ['tags' => ['a']], ['tags', 'x']), 'A name below a list is no place.');
  }

  /**
   * Tests a refined shell keeps the schemas it was composed of.
   *
   * A copy bound to its container's values (readingFrom()) holds the
   * composed schema, the refined child's very schemas in it, and has the
   * refined shell's source.
   */
  public function testRefinedShellKeepsItsChild(): void {
    $children = static::children('City');
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('entries')->willReturn($children);
    $child->method('withPorts')->willReturnSelf();
    $shell = DefinitionSchema::withProperties(DefinitionSchema::shell(MapDataDefinition::create()->setLabel('Where')), static::schemas($children));
    $entry = new Entry('where', $shell, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $refined_children = static::children('Town');
    $composed = $entry->withSchema(DefinitionSchema::withProperties($entry->schema(), static::schemas($refined_children)));
    $bound = $composed->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($bound);
    $this->assertSame($refined_children->entry('city')?->schema(), DefinitionSchema::propertyOf($bound->schema(), 'city'), 'A bound copy holds the composed schema, the refined child\'s very schema in it.');
    $this->assertSame('Town', DefinitionSchema::propertyOf($bound->schema(), 'city')?->get('title'));
    $this->assertTrue($bound->sameSource($composed), 'It has the refined shell\'s source.');
    $this->assertFalse($bound->sameSource($entry), 'Not the sealed one\'s.');
  }

  /**
   * Tests which entries have the same source.
   *
   * The very object of the schema, or of a host entry's definition: what
   * the served contract tells a key a refinement moved by
   * (ContractEmitter::frame()).
   */
  public function testTheSameSource(): void {
    $schema = DefinitionSchema::of(static::map());
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('entries')->willReturn(static::children('City'));
    $child->method('withPorts')->willReturnSelf();
    $sealed = new Entry('where', $schema, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $this->assertTrue($sealed->sameSource($sealed), 'An entry and itself.');
    $copy = $sealed->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($copy);
    $this->assertTrue($copy->sameSource($sealed), 'A port copy and its original.');
    $this->assertTrue($sealed->sameSource($copy), 'Its original and the port copy.');
    $mount = new Entry('third_party_settings', $schema, mounted: ['module' => new Attachment($child, reads: ['venue' => 'venue'])]);
    $mounted = $mount->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($mounted);
    $this->assertNotSame($mount, $mounted);
    $this->assertTrue($mounted->sameSource($mount), 'A port copy of a mount and its original.');
    $refined = $sealed->withSchema(DefinitionSchema::withProperties($schema, static::schemas(static::children('City'))));
    $this->assertFalse($refined->sameSource($sealed), 'A refined shell and its sealed entry, even where the child changed nothing.');
    $this->assertTrue($sealed->withSchema($schema)->sameSource($sealed), 'The schema handed back keeps the source.');
    $definition = static::map();
    $one = Entry::ofHost('where', $definition);
    $two = Entry::ofHost('where', $definition);
    $this->assertTrue($one->sameSource($two), 'Two host entries of one definition object.');
    $this->assertFalse($one->sameSource(Entry::ofHost('where', static::map())), 'Not of an equal one.');
    $read = new Entry('where', $one->schema());
    $this->assertFalse($one->sameSource($read), 'A host entry and an entry of its schema.');
    $this->assertFalse($read->sameSource($one), 'Either way round.');
  }

  /**
   * Tests a host entry reads its schema on the first ask, and keeps it.
   *
   * Nothing is read at construction, so a definition that holds itself is
   * refused at schema(). A copy for ports shares the schema read. A host
   * entry handed another schema holds that schema alone; handed a host
   * definition with its schema (withHost()), it holds both.
   */
  public function testHostIsReadOnTheFirstAsk(): void {
    $definition = static::map();
    $entry = Entry::ofHost('where', $definition);
    $this->assertSame($definition, $entry->host());
    $schema = $entry->schema();
    $this->assertSame(static::json(DefinitionSchema::of($definition)), static::json($schema), 'Its schema is the read of its definition.');
    $this->assertSame($schema, $entry->schema(), 'A second ask returns the same object.');
    $child = $this->createMock(SurfaceInterface::class);
    $child->method('entries')->willReturn(new Entries([]));
    $child->method('withPorts')->willReturnSelf();
    $bound = Entry::ofHost('where', $definition, attachment: new Attachment($child, reads: ['venue' => 'venue']));
    $copy = $bound->readingFrom(['venue' => 'hall']);
    $this->assertNotNull($copy);
    $this->assertSame($bound->schema(), $copy->schema(), 'A copy for ports shares the schema read.');
    $this->assertSame($definition, $copy->host());
    $moved = $entry->withSchema(Schema::string());
    $this->assertNull($moved->host(), 'Handed another schema, it holds that alone.');
    $answer = Surface::deepClone($definition);
    $refined = $entry->withHost($answer, $schema);
    $this->assertSame([$answer, $schema], [$refined->host(), $refined->schema()], 'withHost() holds both.');

    $self = MapDataDefinition::create();
    $self->setPropertyDefinition('again', $self);
    $holds = Entry::ofHost('loop', $self);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('holds itself');
    $holds->schema();
  }

  /**
   * Tests each kind serializes its source alone.
   */
  public function testEachKindSerializesItsSource(): void {
    $definition = static::map();
    $schema = DefinitionSchema::of($definition);
    $entry = new Entry('where', $schema);
    $bytes = serialize($entry);
    $this->assertStringContainsString(DefinitionSchema::MODEL, $bytes, 'An entry serializes its schema.');
    $this->assertStringNotContainsString('MapDataDefinition";', $bytes, 'And no definition.');
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize($bytes);
    $this->assertInstanceOf(Entry::class, $woken);
    $this->assertSame(static::json($schema), static::json($woken->schema()), 'Unserialized, it answers schema() with an equal schema.');

    $first = Entry::ofHost('where', static::map());
    $unread = serialize($first);
    $first->schema();
    $this->assertSame($unread, serialize($first), 'A host entry serializes the same whether or not its schema was read.');
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, $unread, 'It serializes no schema.');
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $back = unserialize($unread);
    $this->assertInstanceOf(Entry::class, $back);
    // Core drops a map's properties when it serializes the map, which a
    // data surface keeps beside itself (SerializedMaps); an entry alone
    // does not. A schema keeps them.
    $this->assertSame('{"title":"Where","x-surface-model":{"type":"map","definitionClass":"Drupal\\\\Core\\\\TypedData\\\\MapDataDefinition"}}', static::json($back->schema()), 'Unserialized alone, a host map reads with no properties.');
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
      public function refine(string $name, Schema $key, array $values): array {
        return [];
      }

    };
  }

}
