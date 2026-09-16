<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\SurfaceEntry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the collection a surface is sealed with.
 *
 * Three claims live here and nowhere else. The map is validated once,
 * at construction, so a surface that named a key twice or refined
 * against a key nobody declared never reaches a reader at all. The
 * order keys were declared in is the order everything downstream sees
 * them in, because a generated form renders that order and the emitted
 * tool schema is compared against a committed document. And a sealed
 * map cannot be written to, which is the same statement the builder
 * makes about itself, kept true of the thing the builder produced.
 *
 * @see \Drupal\data_surface\DefinitionMap
 * @see \Drupal\data_surface\SurfaceEntry
 */
#[Group('data_surface')]
class DefinitionMapTest extends UnitTestCase {

  /**
   * Tests that the declaration order survives every way of reading it.
   */
  public function testDeclarationOrderIsPreserved(): void {
    $map = DefinitionMap::fromArrays([
      'zebra' => DataDefinition::create('string'),
      'aardvark' => DataDefinition::create('string'),
      'moose' => DataDefinition::create('string'),
    ]);

    $this->assertSame(['zebra', 'aardvark', 'moose'], $map->names());
    $this->assertSame(['zebra', 'aardvark', 'moose'], array_keys(iterator_to_array($map)));
    $this->assertSame(['zebra', 'aardvark', 'moose'], array_keys($map->toArray()));
    $this->assertCount(3, $map);
  }

  /**
   * Tests that iterating hands back name => definition.
   */
  public function testIterationYieldsDefinitions(): void {
    $title = DataDefinition::create('string')->setLabel('Title');
    $map = DefinitionMap::fromArrays(['title' => $title]);

    foreach ($map as $name => $definition) {
      $this->assertSame('title', $name);
      $this->assertSame($title, $definition);
    }
    $this->assertSame($title, $map->get('title'));
    $this->assertSame($title, $map['title']);
    $this->assertTrue(isset($map['title']));
    $this->assertNull($map->get('nothing'));
    $this->assertFalse($map->has('nothing'));
  }

  /**
   * Tests that a key declared twice is refused.
   */
  public function testDuplicateNameRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The surface key "title" is declared twice.');
    new DefinitionMap([
      new SurfaceEntry('title', DataDefinition::create('string')),
      new SurfaceEntry('title', DataDefinition::create('integer')),
    ]);
  }

  /**
   * Tests that a refinement edge naming nothing is refused.
   */
  public function testUnknownDependencyRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refinement dependency "kind" of "detail" is not a surface definition.');
    new DefinitionMap([
      new SurfaceEntry('detail', DataDefinition::create('string'), dependencies: ['kind']),
    ]);
  }

  /**
   * Tests that a refinement target naming nothing is refused.
   */
  public function testUnknownRefinementTargetRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Refinement target "detail" is not a surface definition.');
    DefinitionMap::fromArrays(
      definitions: ['kind' => DataDefinition::create('string')],
      refinements: ['detail' => ['kind']],
    );
  }

  /**
   * Tests that locking, refining or contributing to nothing is refused.
   */
  public function testUnknownKeyInBookkeepingRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('"missing" is not a surface definition.');
    DefinitionMap::fromArrays(
      definitions: ['kind' => DataDefinition::create('string')],
      locked: ['missing'],
    );
  }

  /**
   * Tests the per-key answers the parallel arrays used to give.
   */
  public function testEntriesCarryWhatTheArraysHeld(): void {
    $map = DefinitionMap::fromArrays(
      definitions: [
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
    $this->assertInstanceOf(SurfaceEntry::class, $entry);
    $this->assertSame('detail', $entry->name);
    $this->assertSame(DataSurfaceInterface::OWNER, $entry->contributor);
    $this->assertFalse($entry->locked);
    $this->assertNull($map->entry('nothing'));
  }

  /**
   * Tests that a dependency named by two keys is listed once.
   */
  public function testRefinementDependenciesAreDeduplicated(): void {
    $map = DefinitionMap::fromArrays(
      definitions: [
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
    $map = new DefinitionMap([
      new SurfaceEntry('owned', DataDefinition::create('string')),
      new SurfaceEntry(
        'extended',
        DataDefinition::create('string'),
        contributions: ['other_module' => ['ribbon']],
      ),
      new SurfaceEntry('mounted', DataDefinition::create('string'), contributor: 'other_module'),
    ]);

    $this->assertSame(
      ['extended', 'mounted'],
      array_keys($map->byContributor('other_module')),
    );
    $this->assertSame(['owned', 'extended'], array_keys($map->byContributor(DataSurfaceInterface::OWNER)));
    $this->assertSame(
      ['extended' => ['other_module' => ['ribbon']]],
      $map->contributions(),
    );
  }

  /**
   * Tests that replacing one entry keeps its place and changes nothing else.
   */
  public function testWithReplacesInPlace(): void {
    $map = DefinitionMap::fromArrays(
      definitions: [
        'kind' => DataDefinition::create('string'),
        'detail' => DataDefinition::create('string'),
        'note' => DataDefinition::create('string'),
      ],
      refinements: ['detail' => ['kind']],
      locked: ['detail'],
    );
    $narrowed = DataDefinition::create('string')->setLabel('Narrowed');

    $next = $map->with($map->entry('detail')->withDefinition($narrowed));

    $this->assertNotSame($map, $next);
    $this->assertSame(['kind', 'detail', 'note'], $next->names());
    $this->assertSame($narrowed, $next->get('detail'));
    // Everything else about the key is settled at build time.
    $this->assertTrue($next->isLocked('detail'));
    $this->assertSame(['kind'], $next->dependencies('detail'));
    // And the map it came from is untouched.
    $this->assertNotSame($narrowed, $map->get('detail'));
  }

  /**
   * Tests that a key the map never declared cannot be added by with().
   */
  public function testWithRefusesAnUndeclaredKey(): void {
    $map = DefinitionMap::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('"extra" is not a surface definition.');
    $map->with(new SurfaceEntry('extra', DataDefinition::create('string')));
  }

  /**
   * Tests that the map refuses to be written to through array access.
   */
  public function testArrayAccessIsReadOnly(): void {
    $map = DefinitionMap::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('A definition map cannot be written to');
    $map['extra'] = DataDefinition::create('string');
  }

  /**
   * Tests that the map refuses to have a key taken out of it.
   */
  public function testArrayAccessRefusesUnset(): void {
    $map = DefinitionMap::fromArrays(['kind' => DataDefinition::create('string')]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('A definition map cannot be written to');
    unset($map['kind']);
  }

}
