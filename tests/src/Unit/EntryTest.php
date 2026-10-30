<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Entry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the single answer a data surface gives about one of its keys.
 *
 * The entry exists so that a key's definition, its owner, its locked
 * state, its refinement edges, its contributed values and its refiner
 * chains can never disagree with one another. Refinement is the only
 * thing that changes an entry, and it changes exactly one of those
 * seven things.
 *
 * @see \Drupal\surface\Entry
 */
#[Group('surface')]
class EntryTest extends UnitTestCase {

  /**
   * Tests the defaults a key that declares nothing extra takes.
   */
  public function testDefaults(): void {
    $definition = DataDefinition::create('string');
    $entry = new Entry('title', $definition);

    $this->assertSame('title', $entry->name);
    $this->assertSame($definition, $entry->definition);
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
    $this->assertSame($narrowed, $next->definition);
    $this->assertSame('variant', $next->name);
    $this->assertSame('other_module', $next->contributor);
    $this->assertTrue($next->locked);
    $this->assertSame(['casing'], $next->dependencies);
    $this->assertSame([SurfaceInterface::OWNER => [$refiner]], $next->refiners);
    $this->assertSame(['other_module' => ['ribbon']], $next->contributions);
    // The entry it came from is untouched.
    $this->assertNotSame($narrowed, $entry->definition);
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
      public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
        return $definition;
      }

    };
  }

}
