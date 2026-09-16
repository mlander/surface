<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\SurfaceEntry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the single answer a surface gives about one of its keys.
 *
 * The entry exists so that a key's definition, its owner, its locked
 * state, its refinement edges, its contributed values and its refiner
 * chains can never disagree with one another. Refinement is the only
 * thing that changes an entry, and it changes exactly one of those
 * seven things.
 *
 * @see \Drupal\data_surface\SurfaceEntry
 */
#[Group('data_surface')]
class SurfaceEntryTest extends UnitTestCase {

  /**
   * Tests the defaults a key that declares nothing extra takes.
   */
  public function testDefaults(): void {
    $definition = DataDefinition::create('string');
    $entry = new SurfaceEntry('title', $definition);

    $this->assertSame('title', $entry->name);
    $this->assertSame($definition, $entry->definition);
    $this->assertSame(DataSurfaceInterface::OWNER, $entry->contributor);
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
    $entry = new SurfaceEntry(
      name: 'variant',
      definition: DataDefinition::create('string'),
      contributor: 'other_module',
      locked: TRUE,
      dependencies: ['casing'],
      refiners: [DataSurfaceInterface::OWNER => [$refiner]],
      contributions: ['other_module' => ['ribbon']],
    );
    $narrowed = DataDefinition::create('string')->setLabel('Narrowed');

    $next = $entry->withDefinition($narrowed);

    $this->assertNotSame($entry, $next);
    $this->assertSame($narrowed, $next->definition);
    $this->assertSame('variant', $next->name);
    $this->assertSame('other_module', $next->contributor);
    $this->assertTrue($next->locked);
    $this->assertSame(['casing'], $next->dependencies);
    $this->assertSame([DataSurfaceInterface::OWNER => [$refiner]], $next->refiners);
    $this->assertSame(['other_module' => ['ribbon']], $next->contributions);
    // The entry it came from is untouched.
    $this->assertNotSame($narrowed, $entry->definition);
  }

  /**
   * Tests the three ways a provider counts as having touched a key.
   */
  public function testTouchedBy(): void {
    $owned = new SurfaceEntry('owned', DataDefinition::create('string'));
    $this->assertTrue($owned->touchedBy(DataSurfaceInterface::OWNER));
    $this->assertFalse($owned->touchedBy('other_module'));

    $mounted = new SurfaceEntry('mounted', DataDefinition::create('string'), contributor: 'other_module');
    $this->assertTrue($mounted->touchedBy('other_module'));

    $extended = new SurfaceEntry(
      'extended',
      DataDefinition::create('string'),
      contributions: ['other_module' => ['ribbon']],
    );
    $this->assertTrue($extended->touchedBy('other_module'));

    $refined = new SurfaceEntry(
      'refined',
      DataDefinition::create('string'),
      refiners: ['other_module' => [static::refiner()]],
    );
    $this->assertTrue($refined->touchedBy('other_module'));
  }

  /**
   * Builds a refiner that narrows nothing.
   *
   * What an entry does with a refiner is carry it, so the only thing
   * asked of this one is that it is the right kind of object.
   *
   * @return \Drupal\data_surface\DataSurfaceRefinerInterface
   *   The refiner.
   */
  protected static function refiner(): DataSurfaceRefinerInterface {
    return new class() implements DataSurfaceRefinerInterface {

      /**
       * {@inheritdoc}
       */
      public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
        return $definition;
      }

    };
  }

}
