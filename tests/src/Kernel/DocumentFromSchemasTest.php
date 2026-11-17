<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\SchemaEmitCases;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the served document is written from schemas.
 *
 * ContractEmitter, DocumentHead, Conditionals and PropertySchema read a
 * key's facts from its entry's schema (Entry::schema()) and hand on
 * schemas; a port's key is held as its schema. This test holds:
 * - over the examples, emitting the four documents (emit() and
 *   emitFull(), each with and without widget hints) derives no definition
 *   of any key refinement does not read: a key a refiner refines is
 *   handed to it as a definition, and a key a refiner watches is handed
 *   to the watched-value check as one; neither is the emitter's;
 * - the one place the emitter derives a definition is a declared rule's
 *   key whose rule narrows it: the rule is applied to a definition;
 * - the documents of the hand-built cases (SchemaEmitCases) are the ones
 *   the emitter wrote from definitions (taken at 38648793), fresh and
 *   woken;
 * - a port's schema is the one its container bound, read through a
 *   child, and its definition is derived from it, equal to the one
 *   handed.
 *
 * What the derivation scan cannot see: an entry refinement makes during
 * the emit and drops after it, which no walk of the built surface
 * reaches. The counter rig of 2026-11-16 counts those
 * (docs/decisions.md#the-document-is-written-from-schemas).
 *
 * Decision: see docs/decisions.md#the-document-is-written-from-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DocumentFromSchemasTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use UserCreationTrait;

  /**
   * The data surfaces walked, and the entries refinement does not read.
   *
   * Each a sealed entry no refiner refines or watches, at any depth of a
   * built example: every one that derives no definition while the four
   * documents are emitted.
   */
  protected const WALKED = [68, 156];

  /**
   * The documents of each hand-built case, as emitted at 38648793.
   *
   * By case, fresh and woken: a SHA-256 of the JSON of every document of
   * the case, in the order SchemaEmitCases::documents() gives them, an
   * exception by its class and message. The class rule's case is emitted
   * fresh alone (SchemaEmitCases::documents()).
   */
  protected const DOCUMENTS = [
    'classRule' => 'cb9a4ff02a85d4c5c2f16fdb443f81a4685c6b529d47d741d80d0cbe0f81e1e3',
    'twoLevelPort' => 'd3d5cf01a93b0d86347ef7d589150e0bd8fc480722b47350ff60ec2ff0fec46b',
    'twoLevelPort woken' => 'd3d5cf01a93b0d86347ef7d589150e0bd8fc480722b47350ff60ec2ff0fec46b',
    'shapes' => 'cf5bf93f0e2487f90f82dda99a5fdf1f13d886bc42c20353aca6c8edaccb64f7',
    'shapes woken' => 'cf5bf93f0e2487f90f82dda99a5fdf1f13d886bc42c20353aca6c8edaccb64f7',
    'rules' => '123559cd8e03f627e7beef53ce68516fad724d38045d50fa2a215815312bea32',
    'rules woken' => '123559cd8e03f627e7beef53ce68516fad724d38045d50fa2a215815312bea32',
    'sameAnswer' => '64cc41e725331043947448f1ae8a02774a3d2f13b2fda7c05318a1fa88eb5fb6',
    'sameAnswer woken' => '64cc41e725331043947448f1ae8a02774a3d2f13b2fda7c05318a1fa88eb5fb6',
    'describedList' => 'd0ce48ff4763a3baa6d21f761b626c508ba3682e5af501d79fe125348d278298',
    'describedList woken' => 'd0ce48ff4763a3baa6d21f761b626c508ba3682e5af501d79fe125348d278298',
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
   * Walks a data surface's entries and those of every settled child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $at
   *   Where it is.
   * @param array<string, array{0: \Drupal\surface\Entry, 1: bool}> $entries
   *   Collects every entry, with whether refinement reads it, by where it
   *   is.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   *
   * @return int
   *   The data surfaces walked.
   */
  protected static function walk(SurfaceInterface $surface, string $at, array &$entries, array &$seen = []): int {
    if (isset($seen[spl_object_id($surface)])) {
      return 0;
    }
    $seen[spl_object_id($surface)] = TRUE;
    $walked = 1;
    $watched = [];
    foreach ($surface->getDefinitions()->entries() as $entry) {
      foreach ($entry->dependencies as $path) {
        $watched[explode('.', $path)[0]] = TRUE;
      }
    }
    foreach (['in' => $surface->getDefinitions(), 'out' => $surface->getOutputDefinitions()] as $side => $map) {
      foreach ($map->entries() as $name => $entry) {
        $here = "$at $side:$name";
        $entries[$here] = [$entry, $entry->refiners !== [] || isset($watched[$name])];
        $children = ['child' => $entry->attachment?->settledChild(), 'item' => $entry->list?->item->settledChild()];
        foreach ($entry->variants->variants ?? [] as $id => $variant) {
          $children["variant $id"] = $variant->settledChild();
        }
        foreach ($entry->mounted as $path => $mounted) {
          $children["mount $path"] = $mounted->settledChild();
        }
        foreach (array_filter($children) as $what => $child) {
          $walked += static::walk($child, "$here $what", $entries, $seen);
        }
      }
    }
    return $walked;
  }

  /**
   * Tests emitting the four documents of the examples derives nothing.
   */
  public function testTheEmitterDerivesNoDefinition(): void {
    $derived = new \ReflectionProperty(Entry::class, 'definition');
    $emitter = $this->container->get('surface.contract_emitter');
    $walked = 0;
    $plain = 0;
    $found = [];
    $seen = [];
    foreach ($this->examples() as [$id, $operation, $surface]) {
      $entries = [];
      $walked += static::walk($surface, $id . ':' . ($operation ?? '-'), $entries, $seen);
      $before = [];
      foreach ($entries as $here => [$entry]) {
        $before[$here] = $derived->getValue($entry) !== NULL;
      }
      $values = $surface->getDefaultValues();
      $emitter->emit($surface, $values, $id, $operation);
      $emitter->emit($surface, $values, $id, $operation, NULL, TRUE);
      $emitter->emitFull($surface, $id, $operation);
      $emitter->emitFull($surface, $id, $operation, NULL, TRUE);
      foreach ($entries as $here => [$entry, $refined]) {
        if ($refined || !$entry->isNodeFirst()) {
          continue;
        }
        $plain++;
        if (!$before[$here] && $derived->getValue($entry) !== NULL) {
          $found[] = $here;
        }
      }
    }
    $this->assertSame([], $found, 'Emitting the four documents derives no definition of a key refinement does not read.');
    $this->assertSame(self::WALKED, [$walked, $plain], 'The data surfaces walked and the entries refinement does not read.');
  }

  /**
   * Tests a declared rule's key is the one the emitter derives.
   *
   * The full schema states each rule as written and runs none, so no
   * refinement reads a key: what is derived is the emitter's. A rule that
   * narrows its key is applied to a copy of the key's definition; a rule
   * that rules its key out is not applied, and the key it reads is not
   * asked.
   */
  public function testOnlyTheRuleKeyDerives(): void {
    $derived = new \ReflectionProperty(Entry::class, 'definition');
    $surface = SchemaEmitCases::rules();
    $this->container->get('surface.contract_emitter')->emitFull($surface, 'hand', 'op', 'Op', TRUE);
    $asked = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      if ($derived->getValue($entry) !== NULL) {
        $asked[] = (string) $name;
      }
    }
    $this->assertSame(['name', 'list'], $asked, 'The keys a rule narrows, and no other.');
  }

  /**
   * Tests the hand-built cases' documents are the ones written before.
   *
   * Each case is where a key's definition and its schema could part: a
   * key of a class outside the three, refined by its child and again, a
   * port read through two levels, shapes in a list, in a shape and in a
   * collection's item, declared rules, and a key refined to an equal
   * copy beside a port copy and a key inside a refined shell, a
   * collection with a description, fresh and
   * woken from serialize() (the class rule's fresh alone).
   */
  public function testTheCasesAreWrittenAsBefore(): void {
    $documents = SchemaEmitCases::documents($this->container->get('surface.contract_emitter'));
    $cases = [];
    foreach ($documents as $label => $document) {
      [$case, $rest] = explode(' ', $label, 2);
      $cases[str_starts_with($rest, 'woken ') ? "$case woken" : $case][] = json_encode($document, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $this->assertSame(self::DOCUMENTS, array_map(static fn (array $each): string => hash('sha256', implode("\n", $each)), $cases));
  }

  /**
   * Tests a port's schema, and the definition derived from it.
   */
  public function testThePortIsHeldAsItsSchema(): void {
    $top = SchemaEmitCases::twoLevelPort();
    $middle = $top->getDefinitions()->entry('mid')->attachment->bound(['cap' => 's']);
    $declared = DefinitionSchema::of(DataDefinition::create('string')->setLabel('Cap'));
    // The middle's port, bound by the top: the top's own declaration.
    $this->assertTrue(DefinitionSchema::same($declared, $middle->getPortSchema('cap')));
    $this->assertNull($middle->getPortSchema('nothing'), 'A name that is no port has no schema.');
    $this->assertNull($top->getPortSchema('cap'), 'A key is no port.');
    // Read through the middle: its child's port holds what the middle
    // wired to it.
    $inner = $middle->getDefinitions()->entry('deep')->attachment->bound($middle->getPortValues());
    $this->assertSame('Middle cap', (string) $inner->getPortSchema('cap')->get('title'));
    $this->assertSame(['cap' => 's'], $inner->getPortValues());
    // The definition is derived on each ask, equal to the one handed.
    $definition = $middle->getPortDefinition('cap');
    $this->assertInstanceOf(DataDefinition::class, $definition);
    $this->assertEquals(['type' => 'string', 'label' => 'Cap'], $definition->toArray());
    $this->assertNotSame($definition, $middle->getPortDefinition('cap'));
    $this->assertNull($middle->getPortDefinition('nothing'));
    // A definition handed to withPorts() is read once, at the door; handed
    // again, equal, it binds nothing new.
    $handed = DataDefinition::create('string')->setLabel('Handed');
    $bound = $middle->withPorts(['cap' => 'm'], ['cap' => $handed]);
    $this->assertSame('Handed', (string) $bound->getPortSchema('cap')->get('title'));
    $this->assertSame($bound, $bound->withPorts(['cap' => 'm'], ['cap' => clone $handed]));
  }

}
