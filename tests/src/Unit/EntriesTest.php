<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Entries;
use Drupal\surface\Entry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests the collection a data surface is sealed with.
 *
 * Three claims live here and nowhere else. The map is validated once,
 * at construction, so a data surface that named a key twice or refined
 * against a key nobody declared never reaches a reader at all. The
 * order keys were declared in is the order everything downstream sees
 * them in, because a generated form renders that order and the emitted
 * tool schema is compared against a committed document. And a sealed
 * map cannot be written to, which is the same statement the builder
 * makes about itself, kept true of the thing the builder produced.
 *
 * @see \Drupal\surface\Entries
 * @see \Drupal\surface\Entry
 */
#[Group('surface')]
class EntriesTest extends UnitTestCase {

  /**
   * Tests that the declaration order survives every way of reading it.
   */
  public function testDeclarationOrderIsPreserved(): void {
    $map = Entries::fromArrays([
      'zebra' => Schema::keywords(['type' => 'string']),
      'aardvark' => Schema::keywords(['type' => 'string']),
      'moose' => Schema::keywords(['type' => 'string']),
    ]);

    $this->assertSame(['zebra', 'aardvark', 'moose'], $map->names());
    $this->assertSame(['zebra', 'aardvark', 'moose'], array_keys(iterator_to_array($map)));
    $this->assertSame(['zebra', 'aardvark', 'moose'], array_keys($map->keys()));
    $this->assertCount(3, $map);
  }

  /**
   * Tests that iterating hands back name => schema.
   */
  public function testIterationYieldsSchemas(): void {
    $schema = Schema::keywords(['type' => 'string', 'title' => 'Title']);
    $map = Entries::fromArrays(['title' => $schema]);

    foreach ($map as $name => $yielded) {
      $this->assertSame('title', $name);
      $this->assertSame($schema, $yielded);
    }
    $this->assertSame($schema, $map->get('title'));
    $this->assertSame($schema, $map['title']);
    $this->assertTrue(isset($map['title']));
    $this->assertNull($map->get('nothing'));
    $this->assertFalse($map->has('nothing'));
  }

  /**
   * Tests that a key declared twice is refused.
   */
  public function testDuplicateNameRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The key "title" is declared twice.');
    new Entries([
      Entry::ofHost('title', DataDefinition::create('string')),
      Entry::ofHost('title', DataDefinition::create('integer')),
    ]);
  }

  /**
   * Tests that a refinement edge naming nothing is refused.
   */
  public function testUnknownDependencyRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refinement dependency "kind" of "detail" is not a data surface definition.');
    new Entries([
      Entry::ofHost('detail', DataDefinition::create('string'), dependencies: ['kind']),
    ]);
  }

  /**
   * Tests that a refinement target naming nothing is refused.
   */
  public function testUnknownRefinementTargetRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refinement target "detail" is not a data surface definition.');
    Entries::fromArrays(
      schemas: ['kind' => DataDefinition::create('string')],
      refinements: ['detail' => ['kind']],
    );
  }

  /**
   * Tests that locking, refining or contributing to nothing is refused.
   */
  public function testUnknownKeyInBookkeepingRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('"missing" is not a data surface definition.');
    Entries::fromArrays(
      schemas: ['kind' => DataDefinition::create('string')],
      locked: ['missing'],
    );
  }

  /**
   * Tests the per-key answers the parallel arrays used to give.
   */
  public function testEntriesCarryWhatTheArraysHeld(): void {
    $map = Entries::fromArrays(
      schemas: [
        'kind' => DataDefinition::create('string'),
        'detail' => DataDefinition::create('string'),
        'note' => DataDefinition::create('string'),
      ],
      refinements: ['detail' => ['kind']],
      locked: ['note'],
    );

    $this->assertTrue($map->isLocked('note'));
    $this->assertFalse($map->isLocked('kind'));
    // A key nobody declared is not locked, because it is not anything.
    $this->assertFalse($map->isLocked('nothing'));
    $this->assertSame(['kind'], $map->dependencies('detail'));
    $this->assertSame([], $map->dependencies('kind'));
    $this->assertSame(['detail' => ['kind']], $map->refinements());
    $this->assertSame(['kind'], $map->refinementDependencies());
    $this->assertSame([], $map->contributions());

    $entry = $map->entry('detail');
    $this->assertInstanceOf(Entry::class, $entry);
    $this->assertSame('detail', $entry->name);
    $this->assertSame(SurfaceInterface::OWNER, $entry->contributor);
    $this->assertFalse($entry->locked);
    $this->assertNull($map->entry('nothing'));
  }

  /**
   * Tests that a dependency named by two keys is listed once.
   */
  public function testRefinementDependenciesAreDeduplicated(): void {
    $map = Entries::fromArrays(
      schemas: [
        'entity_type' => DataDefinition::create('string'),
        'bundle' => DataDefinition::create('string'),
        'field' => DataDefinition::create('string'),
      ],
      refinements: [
        'bundle' => ['entity_type'],
        'field' => ['entity_type', 'bundle'],
      ],
    );

    $this->assertSame(['entity_type', 'bundle'], $map->refinementDependencies());
  }

  /**
   * Tests reading the map by the provider that had a hand in each key.
   */
  public function testByContributor(): void {
    $map = new Entries([
      Entry::ofHost('owned', DataDefinition::create('string')),
      Entry::ofHost('extended', DataDefinition::create('string'),
        contributions: ['other_module' => ['ribbon']],
      ),
      Entry::ofHost('mounted', DataDefinition::create('string'), contributor: 'other_module'),
    ]);

    $this->assertSame(
      ['extended', 'mounted'],
      array_keys($map->byContributor('other_module')),
    );
    $this->assertSame(['owned', 'extended'], array_keys($map->byContributor(SurfaceInterface::OWNER)));
    $this->assertSame(
      ['extended' => ['other_module' => ['ribbon']]],
      $map->contributions(),
    );
  }

  /**
   * Tests that replacing one entry keeps its place and changes nothing else.
   */
  public function testWithReplacesInPlace(): void {
    $map = Entries::fromArrays(
      schemas: [
        'kind' => Schema::string(),
        'detail' => Schema::string(),
        'note' => Schema::string(),
      ],
      refinements: ['detail' => ['kind']],
      locked: ['detail'],
    );
    $narrowed = Schema::string()->title('Narrowed');

    $next = $map->with($map->entry('detail')->withSchema($narrowed));

    $this->assertNotSame($map, $next);
    $this->assertSame(['kind', 'detail', 'note'], $next->names());
    $this->assertSame($narrowed, $next->entry('detail')->schema());
    // Everything else about the key is settled at build time.
    $this->assertTrue($next->isLocked('detail'));
    $this->assertSame(['kind'], $next->dependencies('detail'));
    // And the map it came from is untouched.
    $this->assertNotSame($narrowed, $map->entry('detail')->schema());
  }

  /**
   * Tests that a key the map never declared cannot be added by with().
   */
  public function testWithRefusesAnUndeclaredKey(): void {
    $map = Entries::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('"extra" is not a data surface definition.');
    $map->with(Entry::ofHost('extra', DataDefinition::create('string')));
  }

  /**
   * Tests that the map refuses to be written to through array access.
   */
  public function testArrayAccessIsReadOnly(): void {
    $map = Entries::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The entries cannot be written to');
    $map['extra'] = Schema::keywords(['type' => 'string']);
  }

  /**
   * Tests that the map refuses to have a key taken out of it.
   */
  public function testArrayAccessRefusesUnset(): void {
    $map = Entries::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The entries cannot be written to');
    unset($map['kind']);
  }

}
