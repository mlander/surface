<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a refined shell wakes holding its child's very schemas.
 *
 * A key that holds a child frame (an attachment, variants, a collection,
 * a mount: Entry::isShell()) is composed of its own facts and the schemas
 * its child's entries hold: at the seal (SurfaceBuilder::shellSchema()),
 * and in a refinement, which composes a refined shell of its own schema
 * and the refined child's entries' schemas (Surface::refineNested(),
 * refineMounted(), DefinitionSchema::withProperties()). The counts, by
 * kind of shell, are pinned.
 *
 * Decision: see
 * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-schema-of-its-own
 * and docs/decisions.md#a-refined-shell-is-composed-of-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ShellSchemaCompositionTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

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
   * counted with the composed ones; it is built as before. Since an entry
   * holds its schema alone (2026-11-18) those 16 hold the variants' shell
   * read as a value (Variants::valueSchema()), which holds no property, so
   * they are counted as composed shells with none chosen.
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
    'variants, none chosen, composed, woken' => 16,
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
    $this->installConfig(['surface']);
  }

  /**
   * Tests a refined shell wakes holding its child's very schemas.
   *
   * Every pinned example built for no account, refined against each of
   * its value sets by every kind of refiner, through serialize() and back,
   * the whole refined data surface at once: each shell's schema is the
   * schema of the definition derived from it; and each shell the
   * refinement composed holds, for every key its child left as it was, the
   * very schema the woken
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
          if ($sealed !== NULL && $entry->host() === NULL && $entry->isShell() && !$entry->sameSource($sealed)) {
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
          if (!DefinitionSchema::same($entry->schema(), DefinitionSchema::of(DefinitionFromSchema::forEntry($entry)))) {
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
    $this->assertTrue(DefinitionSchema::same($schema, DefinitionSchema::of(DefinitionFromSchema::forEntry($woken))), 'The woken mount\'s schema is the schema its definition reads.');
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
      $entry->variants !== NULL => SchemaFacts::properties($entry->schema()) !== [] ? 'variants, chosen' : 'variants, none chosen',
      $entry->list !== NULL => 'collection',
      default => 'mount',
    };
    // How a refinement made it.
    $how = match (TRUE) {
      $where !== 'refined' => NULL,
      $sealed !== NULL && $entry->sameSource($sealed) => 'the sealed source',
      $entry->host() === NULL => 'composed',
      default => 'with its definition',
    };
    $shells[$entry] = [$kind, $where, $how];
  }

}
