<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\DoorSurfaces;
use Drupal\Tests\surface\HandCorpus\HandSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use PortableSchema\Builder\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a shell's composed node is the node its definition reads.
 *
 * A key that holds a child frame (an attachment, variants, a collection,
 * a mount: Entry::isShell()) composes its node of its own facts and the
 * nodes its child's entries hold (Entry::schema()). This test reads the
 * node of every shell of every pinned example surface
 * (ExampleSurfacesTrait), built for the account and for no account, and
 * of every hand and door surface (HandSurfaces, DoorSurfaces) with the
 * other forms they are run in; then the same of each data surface refined
 * against each of its value sets by each kind of refiner, and of its map
 * with the variants those values choose resolved. For each, the composed
 * node and DefinitionSchema::of() of the shell's definition are the same
 * (DefinitionSchema::same()). The counts, by kind of shell and whether
 * the entry is the sealed one or a refinement's, are pinned.
 *
 * Decision: see
 * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-node-of-its-own.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ShellNodeCompositionTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;
  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The distinct shell entries read, by kind, sealed or refined.
   *
   * The same with DefinitionMap::$nodeFirst on.
   */
  protected const COUNTS = [
    'attachment, refined' => 106,
    'attachment, sealed' => 59,
    'collection, refined' => 108,
    'collection, sealed' => 30,
    'mount, refined' => 75,
    'mount, sealed' => 2,
    'variants, chosen, refined' => 978,
    'variants, none chosen, refined' => 96,
    'variants, none chosen, sealed' => 43,
  ];

  /**
   * The refined shells, by kind and by what they were built from.
   *
   * "from the refined child": a refinement built the definition from a
   * child and handed it on; "read": a refinement made the definition
   * otherwise (a deep copy), so its properties are read.
   */
  protected const FRAMED = [
    'attachment, from the refined child' => 76,
    'collection, read' => 108,
    'mount, from the refined child' => 39,
    'variants, chosen, from the refined child' => 815,
    'variants, chosen, read' => 163,
    'variants, none chosen, from the refined child' => 80,
    'variants, none chosen, read' => 16,
  ];

  /**
   * The shells of the refined examples woken from serialize(), by kind.
   */
  protected const WOKEN = [
    'attachment' => 842,
    'attachment, from the refined child, woken' => 23,
    'collection' => 490,
    'mount' => 15,
    'mount, from the refined child, woken' => 13,
    'variants, chosen' => 79,
    'variants, chosen, from the refined child, woken' => 79,
    'variants, none chosen' => 725,
    'variants, none chosen, from the refined child, woken' => 16,
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
    'surface_widget_test',
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
    $this->installConfig(['surface']);
  }

  /**
   * Tests every shell's composed node is the node its definition reads.
   */
  public function testTheComposedNodeIsTheRead(): void {
    $shells = new \SplObjectStorage();
    foreach ($this->examples(TRUE) as [, , $surface]) {
      static::collect($surface, $shells, 'sealed');
    }
    foreach ($this->examples() as [, , $surface]) {
      static::collect($surface, $shells, 'sealed');
      foreach ($this->valueSets($surface) as $values) {
        $this->refined($surface, $values, $shells);
      }
    }
    foreach ([...HandSurfaces::NAMES, ...DoorSurfaces::NAMES] as $name) {
      [$surface, $stored, $cases, $alternates] = in_array($name, HandSurfaces::NAMES, TRUE) ? HandSurfaces::build($name) : DoorSurfaces::build($name);
      $forms = [[$surface, $stored]];
      foreach ($alternates as $alternate) {
        $forms[] = [$alternate[0], $alternate[1]];
      }
      foreach ($forms as [$form, $held]) {
        static::collect($form, $shells, 'sealed');
        $this->refined($form, $held, $shells);
        foreach ($cases as $inputs) {
          $this->refined($form, array_replace($held, $inputs), $shells);
        }
      }
    }

    $differ = [];
    $reread = [];
    $counts = [];
    $framed = [];
    $unframed = [];
    foreach ($shells as $entry) {
      assert($entry instanceof Entry);
      [$kind, $where] = $shells[$entry];
      $counts["$kind, $where"] = ($counts["$kind, $where"] ?? 0) + 1;
      $composed = $entry->schema();
      $read = DefinitionSchema::of($entry->definition());
      if (!DefinitionSchema::same($composed, $read)) {
        $differ[] = sprintf('%s (%s, %s): %s', $entry->name, $kind, $where, implode('; ', array_slice(static::nodeDifferences($read, $composed, $entry->name), 0, 3)));
      }
      if ($where === 'sealed' && !static::holdsItsChildNodes($entry, $composed)) {
        $reread[] = sprintf('%s (%s)', $entry->name, $kind);
      }
      if ($where === 'refined') {
        static::framedBy($entry, $composed, $kind, $framed, $unframed);
      }
    }
    ksort($counts);
    ksort($framed);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every shell\'s composed node is the node its definition reads.');
    $this->assertSame([], array_slice($reread, 0, 20), 'Every sealed shell holds the very nodes its child\'s entries hold.');
    $this->assertSame([], array_slice($unframed, 0, 20), 'Every refined shell holds the very nodes of the refined child it was built from, and nothing of a sealed child it was not.');
    $this->assertSame(static::COUNTS, $counts, 'The shells read, by kind, sealed or refined.');
    $this->assertSame(static::FRAMED, $framed, 'The refined shells, by kind and by what they were built from.');
  }

  /**
   * Tests a refined shell wakes with the children it was built from.
   *
   * Every pinned example built for no account, refined against each of
   * its value sets by every kind of refiner, through serialize() and
   * back: each shell's node is the node its definition reads, and each
   * refined child's key in it is that child's entry's node, the very
   * object (with DefinitionMap::$nodeFirst off; with it on, a node-first
   * child's key is read, since such an entry serializes its node alone).
   * And registration.step3 configure for 400 attendees, whose mounted
   * compliance structure asks for 8 stewards: after the round trip its
   * node says so, from the woken structure's own entry.
   */
  public function testRefinedShellWakesWithItsChildren(): void {
    $counts = [];
    $differ = [];
    $unframed = [];
    foreach ($this->examples() as [, , $surface]) {
      foreach ($this->valueSets($surface) as $values) {
        try {
          $refined = $surface->refine($values, SurfaceInterface::EVERY);
        }
        catch (\InvalidArgumentException | \LogicException) {
          continue;
        }
        // The whole refined surface is woken, every class in it, which is
        // the thing under test.
        // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
        $woken = unserialize(serialize($refined));
        $shells = new \SplObjectStorage();
        static::collect($woken, $shells, 'woken');
        foreach ($shells as $entry) {
          assert($entry instanceof Entry);
          [$kind] = $shells[$entry];
          $counts[$kind] = ($counts[$kind] ?? 0) + 1;
          if (!DefinitionSchema::same($entry->schema(), DefinitionSchema::of($entry->definition()))) {
            $differ[] = "$entry->name ($kind)";
          }
          $framed = [];
          $wrong = [];
          static::framedBy($entry, $entry->schema(), $kind, $framed, $wrong);
          // A node-first entry serializes its node alone, so a child's key
          // wakes with a definition derived again, another object than the
          // one the woken shell's definition holds: the shell reads that
          // key (the same node, read), and the identity holds only with
          // the switch off.
          if (!DefinitionMap::$nodeFirst) {
            array_push($unframed, ...$wrong);
          }
          foreach ($framed as $what => $n) {
            $counts["$what, woken"] = ($counts["$what, woken"] ?? 0) + $n;
          }
        }
      }
    }
    ksort($counts);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every woken shell\'s node is the node its definition reads.');
    $this->assertSame([], array_slice($unframed, 0, 20), 'Every woken refined shell holds the very nodes of the refined child it woke with.');
    $this->assertSame(static::WOKEN, $counts, 'The woken shells, by kind and by what they were built from.');

    $resources = $this->container->get('surface.resources');
    $surface = $resources->buildOperation(RegistrationStep3Resource::class, 'configure');
    $refined = $surface->refine(array_replace($surface->getDefaultValues(), ['capacity' => 400]), SurfaceInterface::CODED);
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($refined))->getDefinitions()->entry('third_party_settings');
    $this->assertNotNull($woken);
    $node = $woken->schema();
    $this->assertTrue(DefinitionSchema::same($node, DefinitionSchema::of($woken->definition())), 'The woken mount\'s node is the node its definition reads.');
    $stewards = DefinitionSchema::propertyNode(DefinitionSchema::propertyNode($node, 'surface_examples_compliance') ?? $node, 'stewards');
    $this->assertSame(8, $stewards?->get('minimum'), 'It asks for 8 stewards, as the refined structure says.');
    $frames = (new \ReflectionProperty(Entry::class, 'frames'))->getValue($woken);
    $this->assertIsArray($frames);
    if (!DefinitionMap::$nodeFirst) {
      $this->assertSame($frames['surface_examples_compliance']->getDefinitions()->entry('stewards')?->schema(), $stewards, 'The stewards node is the woken structure\'s own entry\'s node, the very object.');
    }
  }

  /**
   * Tallies what a refined shell's node was composed of.
   *
   * A refined shell made by Surface::refine() holds the refined children
   * its definition was built from (Entry::withDefinition()): each of
   * their keys is that key's entry's node, the very object. At a refined
   * mount a structure no refined child stands for is read, never taken
   * by name from the sealed structure.
   *
   * @param \Drupal\surface\Entry $entry
   *   The shell, refined or woken.
   * @param \PortableSchema\Builder\Schema $node
   *   Its node.
   * @param string $kind
   *   Its kind, as add() names it.
   * @param array<string, int> $framed
   *   The shells by kind and by what they were built from; gains this.
   * @param string[] $wrong
   *   What is not as the rule says; gains it.
   */
  protected static function framedBy(Entry $entry, Schema $node, string $kind, array &$framed, array &$wrong): void {
    $frames = (new \ReflectionProperty(Entry::class, 'frames'))->getValue($entry);
    if ($frames === NULL) {
      // A sealed entry the refinement kept as it was.
      return;
    }
    $what = $frames === [] ? "$kind, read" : "$kind, from the refined child";
    $framed[$what] = ($framed[$what] ?? 0) + 1;
    foreach ($frames as $path => $child) {
      $map = $path === '' ? ($entry->list !== NULL ? DefinitionSchema::itemNode($node) : $node) : DefinitionSchema::propertyNode($node, (string) $path);
      foreach ($child->getDefinitions()->entries() as $name => $child_entry) {
        if ($map === NULL || DefinitionSchema::propertyNode($map, (string) $name) !== $child_entry->schema()) {
          $wrong[] = "$entry->name.$path.$name ($kind): not the refined child's node";
        }
      }
    }
    foreach ($entry->mounted as $path => $attachment) {
      if (isset($frames[$path])) {
        continue;
      }
      $framed["$kind, a structure no refined child stands for"] = ($framed["$kind, a structure no refined child stands for"] ?? 0) + 1;
      $map = DefinitionSchema::propertyNode($node, (string) $path);
      foreach ($attachment->child->getDefinitions()->entries() as $name => $sealed) {
        if ($map !== NULL && DefinitionSchema::propertyNode($map, (string) $name) === $sealed->schema()) {
          $wrong[] = "$entry->name.$path.$name ($kind): the sealed structure's node, taken by name";
        }
      }
    }
  }

  /**
   * Answers whether a sealed shell's node holds its children's nodes.
   *
   * Each property that is a child's key is the very node that key's entry
   * holds: nothing below the shell was read again.
   *
   * @param \Drupal\surface\Entry $entry
   *   The shell, as sealed.
   * @param \PortableSchema\Builder\Schema $node
   *   Its node.
   *
   * @return bool
   *   TRUE when every child's key is its entry's node.
   */
  protected static function holdsItsChildNodes(Entry $entry, Schema $node): bool {
    // Variants hold no child's keys until a variant is chosen, which no
    // sealed shell here is: the placeholder holds none.
    $list = $entry->list !== NULL;
    $frames = match (TRUE) {
      $entry->attachment !== NULL => ['' => $entry->attachment->child],
      $entry->list !== NULL => ['' => $entry->list->item->child],
      $entry->variants !== NULL => [],
      default => array_map(static fn ($attachment): SurfaceInterface => $attachment->child, $entry->mounted),
    };
    foreach ($frames as $path => $child) {
      $map = $list ? DefinitionSchema::itemNode($node) : ($path === '' ? $node : DefinitionSchema::propertyNode($node, (string) $path));
      foreach ($child->getDefinitions()->entries() as $name => $child_entry) {
        if ($map === NULL || DefinitionSchema::propertyNode($map, (string) $name) !== $child_entry->schema()) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Collects the shells of a data surface refined against one value set.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values.
   * @param \SplObjectStorage $shells
   *   The shells, each with its kind and where it was found; gains these.
   */
  protected function refined(SurfaceInterface $surface, array $values, \SplObjectStorage $shells): void {
    foreach (self::KINDS as $kind) {
      try {
        static::collect($surface->refine($values, $kind), $shells, 'refined');
      }
      catch (\InvalidArgumentException | \UnexpectedValueException | \LogicException) {
        // A value set no refinement takes, as a request is refused.
        continue;
      }
    }
    foreach ($surface->getDefinitions()->withVariantsResolved($values)->entries() as $entry) {
      static::add($entry, $shells, 'refined');
    }
  }

  /**
   * Collects the shells a data surface reaches.
   *
   * Its keys and outputs, and the same of each settled child: attached,
   * each variant, a collection's item, a mounted structure.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param \SplObjectStorage $shells
   *   The shells, each with its kind and where it was found; gains these.
   * @param string $where
   *   Either 'sealed' or 'refined'.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   */
  protected static function collect(SurfaceInterface $surface, \SplObjectStorage $shells, string $where, array &$seen = []): void {
    if (isset($seen[spl_object_id($surface)])) {
      return;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ([$surface->getDefinitions(), $surface->getOutputDefinitions()] as $map) {
      foreach ($map->entries() as $entry) {
        static::add($entry, $shells, $where);
        $children = [$entry->attachment?->settledChild(), $entry->list?->item->settledChild()];
        foreach ($entry->variants->variants ?? [] as $attachment) {
          $children[] = $attachment->settledChild();
        }
        foreach ($entry->mounted as $attachment) {
          $children[] = $attachment->settledChild();
        }
        foreach (array_filter($children) as $child) {
          static::collect($child, $shells, 'sealed', $seen);
        }
      }
    }
  }

  /**
   * Adds an entry, when it is a shell, with its kind.
   *
   * @param \Drupal\surface\Entry $entry
   *   The entry.
   * @param \SplObjectStorage $shells
   *   The shells; gains it.
   * @param string $where
   *   Either 'sealed' or 'refined'.
   */
  protected static function add(Entry $entry, \SplObjectStorage $shells, string $where): void {
    if (!$entry->isShell() || $shells->contains($entry)) {
      return;
    }
    $kind = match (TRUE) {
      $entry->attachment !== NULL => 'attachment',
      $entry->variants !== NULL => DefinitionSchema::isMap($entry->definition()) ? 'variants, chosen' : 'variants, none chosen',
      $entry->list !== NULL => 'collection',
      default => 'mount',
    };
    $shells[$entry] = [$kind, $where];
  }

}
