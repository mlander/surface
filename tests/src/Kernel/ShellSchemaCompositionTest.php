<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Entries;
use Drupal\surface\Entry;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\DoorSurfaces;
use Drupal\Tests\surface\HandCorpus\HandSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use PortableSchema\Builder\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a shell's composed schema is the schema its definition reads.
 *
 * A key that holds a child frame (an attachment, variants, a collection,
 * a mount: Entry::isShell()) composes its schema of its own facts and the
 * schemas its child's entries hold: at the seal (Entry::sealed()), and in a
 * refinement, which composes a refined shell of its own schema and the
 * refined child's entries' schemas (Surface::refineNested(),
 * refineMounted(), DefinitionSchema::withProperties()). This test reads
 * the schema of every shell of every pinned example surface
 * (ExampleSurfacesTrait), built for the account and for no account, and
 * of every hand and door surface (HandSurfaces, DoorSurfaces) with the
 * other forms they are run in; then the same of each data surface refined
 * against each of its value sets by each kind of refiner. For each, the
 * schema and DefinitionSchema::of() of the shell's definition are the same
 * (DefinitionSchema::same()); and each refined shell's schema is the schema
 * of the definition the refinement built before 2026-11-16
 * (Attachment::mapOf(), Variants::definitionFor(), the mount's map),
 * which the test builds again. The counts, by kind of shell and how the
 * entry was made, are pinned.
 *
 * Decision: see
 * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-schema-of-its-own
 * and docs/decisions.md#a-refined-shell-is-composed-of-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ShellSchemaCompositionTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;
  use ExampleSurfacesTrait;
  use SealedShellsTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The distinct shell entries read, by kind, sealed or refined.
   */
  protected const COUNTS = [
    'attachment, refined' => 106,
    'attachment, sealed' => 59,
    'collection, refined' => 108,
    'collection, sealed' => 30,
    'mount, refined' => 75,
    'mount, sealed' => 2,
    // 978 and 96 until 2026-11-16: Entries::withVariantsResolved(),
    // deleted with no caller (part 32.8), added 163 and 16 entries.
    'variants, chosen, refined' => 815,
    'variants, none chosen, refined' => 80,
    'variants, none chosen, sealed' => 43,
  ];

  /**
   * The sealed shells compared with the old seal's read, by kind.
   *
   * Those the hand-built surface holds (SealedShellsTrait) included.
   */
  protected const SEALED = [
    'attachment' => 39,
    'collection' => 19,
    'mount' => 3,
    'variants, chosen' => 1,
    'variants, none chosen' => 30,
  ];

  /**
   * The refined shells, by kind and by how the refinement made them.
   *
   * "composed": schema-first, the schema composed of the shell's own schema and
   * the refined child's entries' schemas; "the sealed source": a copy made
   * for its ports, or the sealed entry itself, the child refined to
   * nothing new; "with its definition": the definition built as before,
   * handed with the composed schema (a locked deciding key, a class no schema
   * derives a definition of, a mount its own refiners refined too: no
   * example and no hand or door surface has one, RefinedShellTest builds
   * each); "read": a definition read whole, a refiner's answer (a
   * collection's Count) or a chosen variant with no keys, whose map reads
   * as a value, built as before. Until 2026-11-16 the refinement built
   * every one of them as a definition, and they were counted as "from the
   * refined child" (76 attachments, 39 mounts, 815 chosen and 80 none
   * chosen, the variants with no keys) and "read" (108 collections); the
   * sealed source was not counted. No count fell. Since refiners answer
   * with schemas (2026-11-16), a refiner's answer for a collection (its
   * Count) is applied to the collection's composed schema, so the 108
   * collections counted "read" are "composed".
   */
  protected const REFINED = [
    'attachment, composed' => 76,
    'attachment, the sealed source' => 30,
    'collection, composed' => 108,
    'mount, composed' => 39,
    'mount, the sealed source' => 36,
    'variants, chosen, composed' => 815,
    'variants, none chosen, read' => 80,
  ];

  /**
   * The refined shells checked against the definition built before, by kind.
   *
   * Each shell the refinement made anew: composed, or with its definition
   * built as before (the variants with no keys).
   */
  protected const AS_BEFORE = [
    'attachment, composed' => 76,
    'mount, composed' => 39,
    'variants, composed' => 815,
    'variants, with its definition' => 80,
  ];

  /**
   * The composed shells of the refined examples woken, and every shell.
   *
   * Each woken with the data surface that holds it, through serialize()
   * and back: the properties whose schema was its sealed child's entry's
   * schema ("kept") are again the very schema the woken child's entry holds,
   * and the rest ("its own") are schemas only the shell holds. Until
   * 2026-11-16 a woken refined shell read its kept keys again (36, 134
   * and 13: the "kept" counts), and a chosen variant with no keys (16) was
   * counted with the composed ones; it is built as before.
   */
  protected const WOKEN = [
    'attachment' => 842,
    'attachment, composed, woken' => 23,
    'attachment, composed, woken, its own' => 27,
    'attachment, composed, woken, kept' => 36,
    'collection' => 490,
    'mount' => 15,
    'mount, composed, woken' => 13,
    'mount, composed, woken, its own' => 13,
    'mount, composed, woken, kept' => 13,
    'variants, chosen' => 79,
    'variants, chosen, composed, woken' => 79,
    'variants, chosen, composed, woken, its own' => 23,
    'variants, chosen, composed, woken, kept' => 134,
    'variants, none chosen' => 725,
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
   * Tests every shell's composed schema is the schema its definition reads.
   */
  public function testTheComposedSchemaIsTheRead(): void {
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
    $made = [];
    foreach ($shells as $entry) {
      assert($entry instanceof Entry);
      [$kind, $where, $how] = $shells[$entry];
      $counts["$kind, $where"] = ($counts["$kind, $where"] ?? 0) + 1;
      $composed = $entry->schema();
      $read = DefinitionSchema::of($entry->definition());
      if (!DefinitionSchema::same($composed, $read)) {
        $differ[] = sprintf('%s (%s, %s): %s', $entry->name, $kind, $where, implode('; ', array_slice(static::schemaDifferences($read, $composed, $entry->name), 0, 3)));
      }
      if ($where === 'sealed' && !static::holdsItsChildSchemas($entry, $composed)) {
        $reread[] = sprintf('%s (%s)', $entry->name, $kind);
      }
      if ($how !== NULL) {
        $made["$kind, $how"] = ($made["$kind, $how"] ?? 0) + 1;
      }
    }
    ksort($counts);
    ksort($made);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every shell\'s schema is the schema its definition reads.');
    $this->assertSame([], array_slice($reread, 0, 20), 'Every sealed shell holds the very schemas its child\'s entries hold.');
    $this->assertSame(static::COUNTS, $counts, 'The shells read, by kind, sealed or refined.');
    $this->assertSame(static::REFINED, $made, 'The refined shells, by kind and by how the refinement made them.');
  }

  /**
   * Tests every refined shell's schema is what the refinement built before.
   *
   * Until 2026-11-16 a refinement built a refined shell's definition
   * (Attachment::mapOf() of the key's definition and the refined child,
   * Variants::definitionFor() of the chosen variant and the refined
   * child, the mount's map with each refined structure's map written in
   * it) and its schema was read from that. For every refined shell of the
   * pinned examples (each value set, each kind of refiner) and of the
   * hand and door surfaces (each form, each case) that the refinement
   * made anew, this test builds that definition again from the advertised
   * entry and the child refined as the refinement refines it, and asserts
   * DefinitionSchema::same() between its schema and the refined entry's.
   * A shell a parent ruled out is a refiner's answer, not built from the
   * child, and is counted apart. A shell made with its definition holds
   * the definition built as before: its schema is held to that definition.
   * Counted by kind and how it was made; and every composed schema holds
   * only schemas of a class a schema derives a definition of, every other one
   * the definition built as before.
   */
  public function testTheRefinedSchemaIsWhatTheRefinementBuiltBefore(): void {
    $counts = [];
    $differ = [];
    $check = function (SurfaceInterface $surface, array $values) use (&$counts, &$differ): void {
      foreach (self::KINDS as $kind) {
        try {
          $refined = $surface->refine($values, $kind);
        }
        catch (\InvalidArgumentException | \LogicException) {
          continue;
        }
        foreach ($refined->entries()->entries() as $name => $entry) {
          $sealed = $surface->entries()->entry((string) $name);
          if ($sealed === NULL || !$entry->isShell() || $entry->list !== NULL || $entry->sameSource($sealed)) {
            continue;
          }
          $what = match (TRUE) {
            $entry->attachment !== NULL => 'attachment',
            $entry->variants !== NULL => 'variants',
            default => 'mount',
          };
          if (SchemaFacts::doesNotApply($entry->schema())) {
            $counts["$what, ruled out"] = ($counts["$what, ruled out"] ?? 0) + 1;
            continue;
          }
          $how = match (TRUE) {
            $entry->isSchemaFirst() => 'composed',
            !Entries::derivedClasses($entry->schema()) => 'with its definition, a class no schema derives',
            default => 'with its definition',
          };
          $before = $entry->isSchemaFirst() ? static::builtBefore($surface, $sealed, $values, $kind) : $entry->definition();
          if ($before === NULL) {
            $counts["$what, $how, its child not refined again here"] = ($counts["$what, $how, its child not refined again here"] ?? 0) + 1;
            continue;
          }
          $counts["$what, $how"] = ($counts["$what, $how"] ?? 0) + 1;
          // Texts are compared by what they say: the child is refined again
          // here, and a refiner that makes a text makes another object.
          $differences = static::schemaDifferences(DefinitionSchema::of($before), $entry->schema(), (string) $name, FALSE);
          if ($differences !== []) {
            $differ[] = sprintf('%s (%s, %s): %s', $name, $what, $how, implode('; ', array_slice($differences, 0, 3)));
          }

        }
      }
    };
    foreach ($this->examples() as [, , $surface]) {
      foreach ($this->valueSets($surface) as $values) {
        $check($surface, $values);
      }
    }
    foreach ([...HandSurfaces::NAMES, ...DoorSurfaces::NAMES] as $name) {
      [$surface, $stored, $cases, $alternates] = in_array($name, HandSurfaces::NAMES, TRUE) ? HandSurfaces::build($name) : DoorSurfaces::build($name);
      $forms = [[$surface, $stored]];
      foreach ($alternates as $alternate) {
        $forms[] = [$alternate[0], $alternate[1]];
      }
      foreach ($forms as [$form, $held]) {
        $check($form, $held);
        foreach ($cases as $inputs) {
          $check($form, array_replace($held, $inputs));
        }
      }
    }
    ksort($counts);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every refined shell\'s schema is the schema of the definition the refinement built before.');
    $this->assertSame(static::AS_BEFORE, $counts, 'The refined shells checked, by kind and how they were made.');
  }

  /**
   * Builds the definition a refinement built before for a refined shell.
   *
   * Refines the key's child as Surface::refineNested() and
   * refineMounted() do, against the values given (a data surface here
   * reads no port and withholds no watched value), and builds the
   * definition from the advertised entry and that child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, as sealed.
   * @param \Drupal\surface\Entry $entry
   *   The key, as sealed.
   * @param array $values
   *   The values the data surface was refined against.
   * @param int $kind
   *   The kinds of refiner that ran.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition; NULL where the child refined here is the sealed one,
   *   so the refinement found something this test does not see (a port,
   *   a withheld value).
   */
  protected static function builtBefore(SurfaceInterface $surface, Entry $entry, array $values, int $kind): ?DataDefinitionInterface {
    if ($entry->mounted !== []) {
      $definition = NULL;
      foreach ($entry->mounted as $path => $attachment) {
        $parents = explode('.', (string) $path);
        $child = $attachment->readFrom($values)->bound([]);
        $value = NestedArray::getValue($values, [$entry->name, ...$parents]);
        $answer = $child->refine(array_replace($child->getDefaultValues(), is_array($value) ? $value : []), $kind);
        if ($answer === $child) {
          continue;
        }
        $definition ??= Surface::deepClone($entry->definition());
        $map = $definition;
        foreach (array_slice($parents, 0, -1) as $parent) {
          $map = $map instanceof MapDataDefinition ? $map->getPropertyDefinition($parent) : NULL;
        }
        $shell = $map instanceof MapDataDefinition ? $map->getPropertyDefinition((string) end($parents)) : NULL;
        if ($map instanceof MapDataDefinition && $shell instanceof MapDataDefinition) {
          $map->setPropertyDefinition((string) end($parents), Attachment::mapOf($shell, $answer));
        }
      }
      return $definition;
    }
    if ($entry->variants !== NULL) {
      $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? NULL);
      if ($chosen === NULL) {
        return NULL;
      }
      $child = $entry->variants->variant($chosen)->child;
      $value = $entry->variants->held($chosen, $values[$entry->name] ?? NULL) ?? [];
      return $entry->variants->definitionFor($chosen, $child->refine(array_replace($child->getDefaultValues(), $value), $kind));
    }
    assert($entry->attachment !== NULL);
    $child = $entry->attachment->readFrom($values)->bound([]);
    $value = $values[$entry->name] ?? NULL;
    $refined = $child->refine(array_replace($child->getDefaultValues(), is_array($value) ? $value : []), $kind);
    $definition = $entry->definition();
    return $refined === $child || !$definition instanceof MapDataDefinition ? NULL : Attachment::mapOf($definition, $refined);
  }

  /**
   * Tests every sealed shell's schema is what the seal read before.
   *
   * Until 2026-11-15 the seal built a shell's definition of its own facts
   * and its child's definitions (Attachment::mapOf(), Collection::listOf(),
   * Variants::definitionFor() or placeholder()) and read its schema from
   * it. Now it composes the schema of the shell's own facts around its
   * child's entries' schemas, building no such definition (Entry::sealed()).
   * For every sealed shell of the pinned examples, built for the account
   * and for no account, of the hand and door surfaces, and of a surface
   * built here with every kind of shell (SealedShellsTrait: an attachment,
   * a collection, variants chosen at the seal and variants open, a shell
   * inside a shell and inside a collection's item, a mount whose
   * structure holds a shell), this test builds the definition the old
   * seal built and asserts DefinitionSchema::same() between its schema and
   * the sealed schema. Counted by kind.
   *
   * What this proves differs by where the shell's own facts come from:
   * - The hand-built surface (SealedShellsTrait) keeps the builder's own
   *   shells, so for its shells the reference is the old seal's: a shell
   *   fact the composition drops (required, description, default, a
   *   collection's Count) fails here.
   * - Any variants keep their shell (Variants::$shell), so the same holds.
   * - An example's attachment or collection keeps no copy of the shell
   *   its author described: its reference is rebuilt from the sealed
   *   entry's own derived definition with the child's keys taken out (an
   *   empty map; a list of an empty map). A fact the composition drops is
   *   dropped from that reference too, so for these shells the test proves
   *   only that the child's keys are placed as the old seal placed them.
   *   That the examples' shells say what the old seal said is proven
   *   against the code before 2026-11-15 itself: the comparison of both
   *   sides in one sitting (values, documents, the corpus and the door
   *   digests), and eight hand cases of the adversarial review run against
   *   that code (462 members: schemas, definition trees, documents, accept
   *   and validate, the tool door, the form and refinement, fresh, woken
   *   and woken twice, none differs).
   * - A mount is built as before (SurfaceBuilder::mountedMap()), so its
   *   old definition is its derived one.
   */
  public function testTheSealedSchemaIsWhatTheOldSealRead(): void {
    $counts = [];
    $differ = [];
    $check = static function (string $at, Entry $entry, DataDefinitionInterface $old) use (&$counts, &$differ): void {
      $kind = match (TRUE) {
        $entry->attachment !== NULL => 'attachment',
        $entry->variants !== NULL => DefinitionSchema::holdsProperties($entry->schema()) ? 'variants, chosen' : 'variants, none chosen',
        $entry->list !== NULL => 'collection',
        default => 'mount',
      };
      $counts[$kind] = ($counts[$kind] ?? 0) + 1;
      if (!DefinitionSchema::same($entry->schema(), DefinitionSchema::of($old))) {
        $differ[] = "$at ($kind): " . implode('; ', array_slice(static::schemaDifferences(DefinitionSchema::of($old), $entry->schema(), $at), 0, 3));
      }
    };
    // The surface with every kind of shell, from the builder's own shells.
    [$surface, $shells, $children] = static::everyShell();
    foreach ($shells as $key => $shell) {
      $check("every shell $key", $surface->entries()->entry($key), static::oldSeal($surface, $key, $shell));
    }
    foreach (['surface' => $surface] + $children as $at => $each) {
      foreach ($each->entries()->entries() as $name => $entry) {
        if ($entry->isShell() && !($at === 'surface' && isset($shells[$name]))) {
          $check("every shell $at.$name", $entry, static::rebuilt($entry));
        }
      }
    }
    ksort($counts);
    $every = [
      'attachment' => 2,
      'collection' => 1,
      'mount' => 1,
      'variants, chosen' => 1,
      'variants, none chosen' => 1,
    ];
    $this->assertSame($every, $counts, 'Every kind of shell, by hand: the holder\'s attachment counted once, at its own entry.');
    // The examples and the hand and door surfaces.
    $sealed = new \SplObjectStorage();
    foreach ([TRUE, FALSE] as $declared) {
      foreach ($this->examples($declared) as [, , $example]) {
        static::collect($example, $sealed, 'sealed');
      }
    }
    foreach ([...HandSurfaces::NAMES, ...DoorSurfaces::NAMES] as $name) {
      [$hand, , , $alternates] = in_array($name, HandSurfaces::NAMES, TRUE) ? HandSurfaces::build($name) : DoorSurfaces::build($name);
      static::collect($hand, $sealed, 'sealed');
      foreach ($alternates as [$each]) {
        static::collect($each, $sealed, 'sealed');
      }
    }
    foreach ($sealed as $entry) {
      assert($entry instanceof Entry);
      $check($entry->name, $entry, static::rebuilt($entry));
    }
    ksort($counts);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every sealed shell\'s schema is the schema of the definition the old seal built.');
    $this->assertSame(static::SEALED, $counts, 'The sealed shells compared, by kind, the hand-built ones included.');
  }

  /**
   * Builds what the old seal built for a sealed shell of an example.
   *
   * @param \Drupal\surface\Entry $entry
   *   The sealed shell.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected static function rebuilt(Entry $entry): DataDefinitionInterface {
    if ($entry->variants !== NULL) {
      $chosen = DefinitionSchema::holdsProperties($entry->schema()) ? static::chosenOf($entry) : NULL;
      return $chosen === NULL ? $entry->variants->placeholder() : $entry->variants->definitionFor($chosen);
    }
    $derived = $entry->definition();
    if ($entry->attachment !== NULL && $derived instanceof MapDataDefinition) {
      $shell = clone $derived;
      foreach (array_keys($shell->getPropertyDefinitions()) as $name) {
        $shell->setPropertyDefinition((string) $name, NULL);
      }
      return Attachment::mapOf($shell, $entry->attachment->child);
    }
    if ($entry->list !== NULL && $derived instanceof ListDataDefinition) {
      $shell = clone $derived;
      $shell->setItemDefinition(MapDataDefinition::create());
      return Collection::listOf($shell, $entry->list->item->child);
    }
    return $derived;
  }

  /**
   * Finds the variant a sealed key's schema holds the keys of.
   *
   * @param \Drupal\surface\Entry $entry
   *   A key with variants whose schema is a map.
   *
   * @return string|null
   *   The variant whose child's keys are the schema's, in order.
   */
  protected static function chosenOf(Entry $entry): ?string {
    $keys = array_keys(SchemaFacts::properties($entry->schema()));
    foreach ($entry->variants->variants as $id => $variant) {
      if (array_map('strval', array_keys($variant->child->entries()->keys())) === array_map('strval', $keys)) {
        return (string) $id;
      }
    }
    return NULL;
  }

  /**
   * Tests a refined shell wakes holding its child's very schemas.
   *
   * Every pinned example built for no account, refined against each of
   * its value sets by every kind of refiner, through serialize() and back,
   * the whole refined data surface at once: each shell's schema is the schema
   * its definition reads; and each shell the refinement composed holds,
   * for every key its child left as it was, the very schema the woken
   * child's entry holds, as it did before the round trip (one serialized
   * graph keeps object identity), so nothing is read twice; the keys the
   * child refined are schemas the shell alone holds. And registration.step3
   * configure for 400 attendees, whose mounted compliance structure asks
   * for 8 stewards: after the round trip its schema says so.
   */
  public function testRefinedShellWakesWithItsChildren(): void {
    $counts = [];
    $differ = [];
    $lost = [];
    foreach ($this->examples() as [, , $surface]) {
      foreach ($this->valueSets($surface) as $values) {
        try {
          $refined = $surface->refine($values, SurfaceInterface::EVERY);
        }
        catch (\InvalidArgumentException | \LogicException) {
          continue;
        }
        $kept = [];
        foreach ($refined->entries()->entries() as $name => $entry) {
          $sealed = $surface->entries()->entry((string) $name);
          if ($sealed !== NULL && $entry->isSchemaFirst() && $entry->isShell() && !$entry->sameSource($sealed)) {
            $kept[(string) $name] = static::keptSchemas($entry, $values);
          }
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
        }
        foreach ($kept as $name => $before) {
          $entry = $woken->entries()->entry($name);
          assert($entry !== NULL);
          $after = static::keptSchemas($entry, $values);
          $kind = $shells[$entry][0] ?? 'none';
          $counts["$kind, composed, woken"] = ($counts["$kind, composed, woken"] ?? 0) + 1;
          foreach ($before as $path => $same) {
            $counts["$kind, composed, woken, " . ($same ? 'kept' : 'its own')] = ($counts["$kind, composed, woken, " . ($same ? 'kept' : 'its own')] ?? 0) + 1;
            if ($same !== ($after[$path] ?? NULL)) {
              $lost[] = "$name.$path ($kind)";
            }
          }
        }
      }
    }
    ksort($counts);
    $this->assertSame([], array_slice($differ, 0, 20), 'Every woken shell\'s schema is the schema its definition reads.');
    $this->assertSame([], array_slice($lost, 0, 20), 'Every woken composed shell holds the very schema of its woken child\'s entry where it did before.');
    $this->assertSame(static::WOKEN, $counts, 'The woken shells, by kind.');

    $resources = $this->container->get('surface.resources');
    $surface = $resources->buildOperation(RegistrationStep3Resource::class, 'configure');
    $values = array_replace($surface->getDefaultValues(), ['capacity' => 400]);
    $refined = $surface->refine($values, SurfaceInterface::CODED);
    $before = static::keptSchemas($refined->entries()->entry('third_party_settings'), $values);
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($refined))->entries()->entry('third_party_settings');
    $this->assertNotNull($woken);
    $schema = $woken->schema();
    $this->assertTrue(DefinitionSchema::same($schema, DefinitionSchema::of($woken->definition())), 'The woken mount\'s schema is the schema its definition reads.');
    $stewards = DefinitionSchema::propertyOf(DefinitionSchema::propertyOf($schema, 'surface_examples_compliance') ?? $schema, 'stewards');
    $this->assertSame(8, $stewards?->get('minimum'), 'It asks for 8 stewards, as the refined structure says.');
    $this->assertFalse($before['surface_examples_compliance.stewards'] ?? TRUE, 'The stewards schema is the refined structure\'s own.');
    $this->assertSame($before, static::keptSchemas($woken, $values), 'Woken, it holds the very schema of each key the structure left as it was.');
  }

  /**
   * Tells, for each child's key in a composed shell, whether it is kept.
   *
   * @param \Drupal\surface\Entry $entry
   *   A refined shell, schema-first.
   * @param array $values
   *   The values its data surface was refined against, which choose a
   *   variant.
   *
   * @return array<string, bool>
   *   By the key's path below the shell (`<module>.<key>` at a mount):
   *   TRUE where the shell's schema holds the very schema the sealed child's
   *   entry holds, FALSE where it holds another.
   */
  protected static function keptSchemas(Entry $entry, array $values): array {
    $schema = $entry->schema();
    $frames = match (TRUE) {
      $entry->attachment !== NULL => ['' => $entry->attachment->child],
      $entry->variants !== NULL => ($chosen = $entry->variants->chosen($values[$entry->variants->by] ?? NULL)) === NULL ? [] : ['' => $entry->variants->variant($chosen)->child],
      default => array_map(static fn ($attachment): SurfaceInterface => $attachment->child, $entry->mounted),
    };
    $kept = [];
    foreach ($frames as $path => $child) {
      $map = $path === '' ? $schema : DefinitionSchema::propertyOf($schema, (string) $path);
      foreach ($child->entries()->entries() as $name => $child_entry) {
        $at = $path === '' ? (string) $name : "$path.$name";
        $kept[$at] = $map !== NULL && DefinitionSchema::propertyOf($map, (string) $name) === $child_entry->schema();
      }
    }
    return $kept;
  }

  /**
   * Answers whether a sealed shell's schema holds its children's schemas.
   *
   * Each property that is a child's key is the very schema that key's entry
   * holds: nothing below the shell was read again.
   *
   * @param \Drupal\surface\Entry $entry
   *   The shell, as sealed.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   *
   * @return bool
   *   TRUE when every child's key is its entry's schema.
   */
  protected static function holdsItsChildSchemas(Entry $entry, Schema $schema): bool {
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
      $map = $list ? DefinitionSchema::itemOf($schema) : ($path === '' ? $schema : DefinitionSchema::propertyOf($schema, (string) $path));
      foreach ($child->entries()->entries() as $name => $child_entry) {
        if ($map === NULL || DefinitionSchema::propertyOf($map, (string) $name) !== $child_entry->schema()) {
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
        static::collect($surface->refine($values, $kind), $shells, 'refined', sealed: $surface);
      }
      catch (\InvalidArgumentException | \UnexpectedValueException | \LogicException) {
        // A value set no refinement takes, as a request is refused.
        continue;
      }
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
   * @param \Drupal\surface\SurfaceInterface|null $sealed
   *   For a refined data surface, the one it was refined from.
   */
  protected static function collect(SurfaceInterface $surface, \SplObjectStorage $shells, string $where, array &$seen = [], ?SurfaceInterface $sealed = NULL): void {
    if (isset($seen[spl_object_id($surface)])) {
      return;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ([$surface->entries(), $surface->outputEntries()] as $map) {
      foreach ($map->entries() as $name => $entry) {
        static::add($entry, $shells, $where, $sealed?->entries()->entry((string) $name));
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
   * @param \Drupal\surface\Entry|null $sealed
   *   For a refined entry, the entry it was refined from.
   */
  protected static function add(Entry $entry, \SplObjectStorage $shells, string $where, ?Entry $sealed = NULL): void {
    if (!$entry->isShell() || $shells->contains($entry)) {
      return;
    }
    $kind = match (TRUE) {
      $entry->attachment !== NULL => 'attachment',
      $entry->variants !== NULL => DefinitionSchema::isMap($entry->definition()) ? 'variants, chosen' : 'variants, none chosen',
      $entry->list !== NULL => 'collection',
      default => 'mount',
    };
    // How a refinement made it, asked before anything reads its schema: a
    // definition-first entry handed a schema holds it already.
    $how = match (TRUE) {
      $where !== 'refined' => NULL,
      $sealed !== NULL && $entry->sameSource($sealed) => 'the sealed source',
      $entry->isSchemaFirst() => 'composed',
      (new \ReflectionProperty(Entry::class, 'schema'))->getValue($entry) !== NULL => 'with its definition',
      default => 'read',
    };
    $shells[$entry] = [$kind, $where, $how];
  }

}
