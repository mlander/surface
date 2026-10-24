<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\TypeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\VariantMismatchException;
use Drupal\data_surface\Pipeline\Violation;

/**
 * Holds both documents to the pipeline, on the accepted document.
 *
 * The invariant of
 * docs/decisions.md#two-documents-never-stricter-than-the-runtime: for
 * a table of inputs, each is run through the pipeline (accept(), then
 * validate() over what is stored), and the document accept() makes of it
 * (the input itself when accept() refuses it outright) is validated
 * against the full schema (emitFull()) and the schema for these answers
 * (emit(), built for the document), with a standard validator that
 * asserts no `format` and fills no `default`. Every accepted document is
 * accepted by both. A refused one is refused by both where the schema is
 * exact, and may be accepted where it says it is not: a row says which,
 * or leaves it to the schema's own marks at each refused key
 * (inexactAt()).
 *
 * The host is a kernel test with the pipeline at pipeline().
 */
trait NeverStricterTrait {

  use ContractValidationTrait;

  /**
   * Holds one data surface to its table of documents.
   *
   * @param string $id
   *   The resource's id, for the documents.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, built in its operation.
   * @param array $stored
   *   What storage holds.
   * @param array<string, array{0: array, 1: bool|null, 2?: bool|null, 3?: bool|null}> $rows
   *   By label: the input; whether the pipeline accepts it, or NULL to
   *   take its verdict as given; and, for a refused one, whether the full
   *   schema and the schema for these answers accept its document anyway:
   *   FALSE, exact, unless given, and NULL for what the schema's marks at
   *   each refused key say.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $declared
   *   The data surface the full schema is emitted from, built for the
   *   account alone, when access in it differs from $surface's; NULL for
   *   $surface.
   *
   * @return int
   *   How many rows the pipeline accepted.
   */
  protected function assertTable(string $id, DataSurfaceInterface $surface, array $stored, array $rows, ?DataSurfaceInterface $declared = NULL): int {
    $pipeline = $this->pipeline();
    $emitter = \Drupal::service('data_surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    // As a client holds them: an empty object stays one.
    $full = json_decode((string) json_encode($emitter->emitFull($declared ?? $surface, $id)->document['schema']));
    $accepted_rows = 0;
    foreach ($rows as $label => $row) {
      [$input, $accepts] = $row;
      $violations = [];
      try {
        $accepted = $pipeline->accept($surface, $input, $stored);
        $set = $pipeline->validate($surface, $accepted, $stored);
        $verdict = $set->isEmpty();
        $violations = iterator_to_array($set, FALSE);
        // As the pipeline writes it: every map an object, an empty one too.
        $document = $pipeline->document($surface, $accepted);
        $these = (array) json_decode((string) json_encode($emitter->emit($surface, $accepted, $id)->document));
      }
      catch (UnknownKeysException | TypeMismatchException | VariantMismatchException) {
        $document = $input;
        $verdict = FALSE;
        $these = (array) json_decode((string) json_encode($emitter->emit($surface, $pipeline->accept($surface, [], $stored), $id)->document));
      }
      $accepts ??= $verdict;
      $this->assertSame($accepts, $verdict, $label . ': the pipeline ' . ($accepts ? 'accepts' : 'refuses') . ' it.');
      $wide_full = $accepts || ($row[2] ?? FALSE) || (array_key_exists(2, $row) && $row[2] === NULL && $this->inexactAt($full, $violations));
      $wide_these = $accepts || ($row[3] ?? FALSE) || (array_key_exists(3, $row) && $row[3] === NULL && $this->inexactAt($these['schema'], $violations));
      $full_accepts = $this->validates($full, $document);
      $these_accepts = $this->validates($these['schema'], $document);
      if ($accepts) {
        $this->assertTrue($full_accepts, $label . ': the full schema accepts its document.');
        $this->assertTrue($these_accepts, $label . ': the schema for these answers accepts its document.');
        // A secret's value is masked as null there, which is read as the
        // key left out (docs/decisions.md#a-secret-is-optional-never-nullable).
        $values = $this->unmasked($surface, $these['values']);
        $this->assertTrue($this->validates($these['schema'], $values), $label . ': the schema for these answers accepts its own values.');
        $accepted_rows++;
        continue;
      }
      // Refused: the schema may only be wider where it says it is not
      // exact; where it is exact it refuses too.
      if (!$wide_full) {
        $this->assertFalse($full_accepts, $label . ': the full schema refuses its document' . static::refusedAt($violations) . '.');
      }
      elseif (!array_key_exists(2, $row) || $row[2] !== NULL) {
        $this->assertSame($wide_full, $full_accepts, $label . ': the full schema accepts its document.');
      }
      if (!$wide_these) {
        $this->assertFalse($these_accepts, $label . ': the schema for these answers refuses its document' . static::refusedAt($violations) . '.');
      }
      elseif (!array_key_exists(3, $row) || $row[3] !== NULL) {
        $this->assertSame($wide_these, $these_accepts, $label . ': the schema for these answers accepts its document.');
      }
    }
    return $accepted_rows;
  }

  /**
   * Answers whether a schema says it is not exact where values were refused.
   *
   * The marks of
   * docs/decisions.md#two-documents-never-stricter-than-the-runtime: a
   * key whose narrowing the schema cannot list (`refine`), one a runtime
   * refiner narrows (`live`), a rule no keyword states
   * (`checkedOnServer`), an advisory `format`, and a stored value kept
   * stale. A refusal of the whole frame is read at the frame.
   *
   * @param array|\stdClass $schema
   *   The schema, as a client decoded it.
   * @param \Drupal\data_surface\Pipeline\Violation[] $violations
   *   What the pipeline refused.
   *
   * @return bool
   *   TRUE when a refused key, or the frame, carries one of the marks.
   */
  protected function inexactAt(array|\stdClass $schema, array $violations): bool {
    $schema = SchemaResolver::inline(SchemaResolver::restored(json_decode((string) json_encode($schema), TRUE)));
    foreach ($violations as $violation) {
      if ($violation->stale) {
        return TRUE;
      }
      // Every object that may state the key: its own property, and one a
      // conditional or a part of allOf states (a variant's, a mixin's).
      $at = [$schema];
      $marked = static::marksInexact($schema);
      foreach (array_filter(explode('.', $violation->fullPath()), static fn (string $segment): bool => $segment !== '') as $segment) {
        $below = [];
        foreach ($at as $object) {
          array_push($below, ...static::under($object, (string) $segment));
        }
        if ($below === []) {
          // A key no schema object names: what the frames above it say.
          break;
        }
        foreach ($below as $object) {
          $marked = $marked || static::marksInexact($object)
            // A secret is never required in a schema: one stored stands
            // when it is left out, which no schema can know.
            // Decision: see
            // docs/decisions.md#a-secret-is-optional-never-nullable.
            || ($violation->code === Violation::REQUIRED && ($object['writeOnly'] ?? FALSE) === TRUE);
        }
        $at = $below;
      }
      if ($marked) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds the schemas of a property or an item below a schema object.
   *
   * @param array $schema
   *   The object, inline.
   * @param string $segment
   *   A property's name, or an item's position.
   *
   * @return list<array>
   *   Its own properties' or items' entry, and each one a conditional or
   *   a part of `allOf` states.
   */
  protected static function under(array $schema, string $segment): array {
    $found = [];
    $own = ctype_digit($segment) ? ($schema['items'] ?? NULL) : ($schema['properties'][$segment] ?? NULL);
    if (is_array($own)) {
      $found[] = $own;
    }
    foreach ($schema['allOf'] ?? [] as $part) {
      foreach ([$part, $part['then'] ?? NULL, $part['else'] ?? NULL] as $branch) {
        if (is_array($branch)) {
          array_push($found, ...static::under($branch, $segment));
        }
      }
    }
    return $found;
  }

  /**
   * Answers whether one schema object carries a mark of inexactness.
   *
   * Each documents a widening: a `format` no validator asserts, a rule
   * only the server runs (`refine`, `live`, a live access answer, a
   * constraint `checkedOnServer`), or a list the site decides left out for
   * its size (`deferred`), stated open and fetched per choice.
   *
   * @param array $schema
   *   The object.
   *
   * @return bool
   *   Whether it says the schema is wider there than the pipeline.
   */
  protected static function marksInexact(array $schema): bool {
    $extension = $schema[ContractEmitter::EXTENSION] ?? [];
    return isset($schema['format'])
      || !empty($extension['refine'])
      || !empty($extension['deferred'])
      || !empty($extension['live'])
      || ($extension['access'] ?? NULL) === 'live'
      || !empty($extension['checkedOnServer']);
  }

  /**
   * Names where the pipeline refused, for a message.
   *
   * @param \Drupal\data_surface\Pipeline\Violation[] $violations
   *   What it refused.
   *
   * @return string
   *   The paths and codes, or nothing for a refusal at accept().
   */
  protected static function refusedAt(array $violations): string {
    $at = array_map(static fn (Violation $violation): string => ($violation->fullPath() === '' ? '(the whole)' : $violation->fullPath()) . ' ' . $violation->code, $violations);
    return $at === [] ? '' : ' (the pipeline refused ' . implode(', ', $at) . ')';
  }

  /**
   * Leaves out each secret a document's values mask as null.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values are of.
   * @param mixed $values
   *   The values, as the document carries them, decoded with objects.
   *
   * @return mixed
   *   The same, a secret holding null left out, in a child or a chosen
   *   variant too.
   */
  protected function unmasked(DataSurfaceInterface $surface, mixed $values): mixed {
    if (!$values instanceof \stdClass) {
      return $values;
    }
    $values = clone $values;
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      if (!property_exists($values, $name)) {
        continue;
      }
      if ($values->{$name} === NULL && DefinitionMetadata::isSecret($entry->definition)) {
        unset($values->{$name});
        continue;
      }
      $child = $entry->list === NULL ? $entry->childFor(json_decode((string) json_encode($values), TRUE)) : NULL;
      if ($child !== NULL) {
        $values->{$name} = $this->unmasked($child, $values->{$name});
      }
    }
    return $values;
  }

}
