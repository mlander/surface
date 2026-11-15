<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests no entry's node goes stale while a request runs.
 *
 * An entry reads its node once and keeps it (Entry::schema()), so the
 * rule it rests on is that a definition an entry holds is never written
 * after the entry is made: the builder seals the definitions, and a
 * refiner writes a deep copy. Nothing checks that at run time. This test
 * is the proof instead: for every pinned example (ExampleSurfacesTrait),
 * built for the account and for no account, and every value set of it
 * (ExampleValueSetsTrait), the node of every entry it can reach is read
 * first: the data surface's own keys and outputs, the children that are
 * settled (attached, variants, a collection's item, a mounted structure),
 * and the same of the data surface refined against the values by each kind
 * of refiner. Then what a request runs is run: the accept and the
 * validation, the documents of each kind (emit() with and without the
 * widget hints, emitFull() with and without them, the narrowed document),
 * the form's envelope, and the tool definitions. Then every entry read is
 * read afresh: DefinitionSchema::of() of its definition says what its
 * kept node says (nodeDifferences()). A refiner or a consumer that wrote
 * a definition it was handed instead of a copy fails here.
 *
 * Decision: see docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EntryNodeIsNeverStaleTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;
  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The data surfaces built, the value sets run, refused, and the entries.
   *
   * The examples built for the account and for no account, the value sets
   * of the former, those the accept refuses, and the distinct entries
   * whose nodes were read and read again.
   */
  protected const COUNTS = [90, 199, 5, 1369];

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
    'surface_openapi',
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
  ];

  /**
   * The kinds of refiner a data surface is refined by, each on its own.
   */
  protected const KINDS = [
    SurfaceInterface::CODED,
    SurfaceInterface::DECLARED,
    SurfaceInterface::RUNTIME,
    SurfaceInterface::STATIC,
    SurfaceInterface::EVERY,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Tests every entry's kept node says what its definition says, after all.
   */
  public function testNoNodeGoesStale(): void {
    $entries = new \SplObjectStorage();
    $surfaces = 0;
    $sets = 0;
    $refused = 0;
    $emitter = $this->container->get('surface.contract_emitter');
    $narrowing = $this->container->get('surface.document_narrowing');
    $builder = $this->container->get('surface.form_builder');
    $pipeline = $this->container->get('surface.pipeline');
    $declared = [];
    foreach ($this->examples(TRUE) as [$id, $operation, $surface]) {
      $surfaces++;
      $declared[] = [$id, $operation, $surface];
      static::readEveryNode($surface, $entries);
    }
    foreach ($this->examples() as [$id, $operation, $surface]) {
      $surfaces++;
      static::readEveryNode($surface, $entries);
      $sets_of = $this->valueSets($surface);
      foreach ($sets_of as $values) {
        $sets++;
        foreach (self::KINDS as $kind) {
          static::readEveryNode($surface->refine($values, $kind), $entries);
        }
      }
      // What a request runs, after the nodes were read.
      $defaults = $surface->getDefaultValues();
      foreach ($sets_of as $name => $values) {
        try {
          $accepted = $pipeline->accept($surface, $values, $defaults);
          iterator_to_array($pipeline->validate($surface, $accepted, $defaults), FALSE);
        }
        catch (\InvalidArgumentException | \UnexpectedValueException) {
          // A value set the accept refuses (a variant's value handed to
          // another): refused as a request is, after what it read.
          $refused++;
        }
        $emitter->emit($surface, $values, $id, $operation);
        $emitter->emit($surface, $values, $id, $operation, NULL, TRUE);
        if ($name !== 'defaults') {
          $narrowing->narrow($surface, $defaults, $values);
        }
        $builder->envelope($surface, $values);
      }
      $emitter->emitFull($surface, $id, $operation);
      $emitter->emitFull($surface, $id, $operation, NULL, TRUE);
    }
    foreach ($declared as [$id, $operation, $surface]) {
      $emitter->emitFull($surface, $id, $operation);
      $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation, NULL, TRUE);
    }
    $tools = $this->container->get('plugin.manager.tool');
    $tools->clearCachedDefinitions();
    $this->assertNotEmpty($tools->getDefinitions(), 'The tool definitions are built.');

    $stale = [];
    foreach ($entries as $entry) {
      assert($entry instanceof Entry);
      array_push($stale, ...static::nodeDifferences(DefinitionSchema::of($entry->definition()), $entry->schema(), $entries[$entry]));
    }
    $this->assertSame([], array_slice($stale, 0, 20), 'Every kept node says what its definition says now.');
    $this->assertSame(self::COUNTS, [$surfaces, $sets, $refused, count($entries)], 'The data surfaces built, the value sets run, those the accept refuses, and the entries read.');
  }

  /**
   * Reads the node of every entry a data surface reaches.
   *
   * Its keys and outputs, and the same of each settled child: attached,
   * each variant, a collection's item, a mounted structure.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param \SplObjectStorage $entries
   *   The entries read, each with where it was found; gains these.
   * @param string $at
   *   Where the data surface is, for the message.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   */
  protected static function readEveryNode(SurfaceInterface $surface, \SplObjectStorage $entries, string $at = '', array &$seen = []): void {
    if (isset($seen[spl_object_id($surface)])) {
      return;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach (['in' => $surface->getDefinitions(), 'out' => $surface->getOutputDefinitions()] as $side => $map) {
      foreach ($map->entries() as $name => $entry) {
        $here = "$at $side:$name";
        $entry->schema();
        if (!$entries->contains($entry)) {
          $entries[$entry] = $here;
        }
        $children = [
          'child' => $entry->attachment?->settledChild(),
          'item' => $entry->list?->item->settledChild(),
        ];
        foreach ($entry->variants->variants ?? [] as $variant => $attachment) {
          $children["variant $variant"] = $attachment->settledChild();
        }
        foreach ($entry->mounted as $path => $attachment) {
          $children["mounted $path"] = $attachment->settledChild();
        }
        foreach (array_filter($children) as $what => $child) {
          static::readEveryNode($child, $entries, "$here $what", $seen);
        }
      }
    }
  }

}
