<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\DoorCorpusRecorder;
use Drupal\Tests\surface\HandCorpus\DoorSurfaces;
use Drupal\Tests\surface\HandCorpus\HandSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PortableSchema\Builder\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a sealed data surface wakes from the build cache as it was sealed.
 *
 * Every sealed entry of the examples, a test module and the hand and door
 * surfaces, at every depth, serialized and unserialized, answers its
 * schema, and the definition an edge derives from it
 * (DefinitionFromSchema::forEntry()), as before.
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
    foreach (['in' => $surface->entries(), 'out' => $surface->outputEntries()] as $side => $map) {
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
        if ($entry->host() !== NULL) {
          return;
        }
        $tree = DoorCorpusRecorder::tree(DefinitionFromSchema::forEntry($entry));
        $before[$here] = [static::said($entry->schema()), $tree];
      });
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $woken = unserialize($bytes);
      static::walk($woken, $at, static function (Entry $entry, string $here) use (&$before, &$differ, &$compared): void {
        if ($entry->host() !== NULL) {
          $differ[] = "$here: keeps its host definition";
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
        if (DoorCorpusRecorder::tree(DefinitionFromSchema::forEntry($entry)) !== $before[$here][1]) {
          $differ[] = "$here: its definition";
        }
      });
    }
    // The one entry that keeps its host definition, m1 of the ruled hand
    // surface, serializes it, and core's map keeps no properties on
    // sleep: its NotAMapDefinition wakes without them. It is not read
    // here.
    $this->assertSame(['hand ruled in:m1: keeps its host definition'], array_slice($differ, 0, 20), 'Every woken entry answers its schema, and the definition derived from it, as before.');
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

}
