<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * Reads a schema the contract emitter wrote as one inline schema.
 *
 * The emitter writes each subsurface once, under the root's `$defs`, and
 * refers to it from each site, beside what is particular to that site
 * (`{"$ref": ..., "title": ..., "x-surface": ...}`), composing it with a
 * narrowing where the site says more than the def
 * (`{"allOf": [{"$ref": ...}, {narrowing}], ...}`). A reader that wants a
 * key's effective schema, keyword by keyword, gets it here:
 *
 * - a local `$ref` (`#/$defs/...`) is replaced by its def, read the same
 *   way, at the place it stood, so the site's keywords around it keep
 *   their order;
 * - an `allOf` holding a reference is a composition: each entry that is
 *   not a conditional (`if`) is merged in, in order, and the conditionals
 *   stay in `allOf`;
 * - merging takes the later schema's value for every keyword it states,
 *   which is right because a narrowing only narrows, with three
 *   exceptions: `properties` are merged one by one, `required` is the
 *   union, and `allOf` the concatenation.
 *
 * A reference that leads back into a def being read is left as it is, so
 * a recursive schema ends. This is data_surface_react's `resolveSchema()`
 * and `inlineSchema()`, in PHP.
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter::reference()
 */
final class SchemaResolver {

  /**
   * Resolves one schema's references, not those of the schemas inside it.
   *
   * @param array $schema
   *   A schema in the document: a property, a `then`, a def.
   * @param array $root
   *   The document's root schema, which holds `$defs`.
   * @param string[] $followed
   *   The defs already followed on the way here; the ones followed now
   *   are added.
   *
   * @return array
   *   The effective schema.
   */
  public static function resolve(array $schema, array $root, array &$followed = []): array {
    $ancestors = $followed;
    $resolved = [];
    foreach ($schema as $keyword => $value) {
      if ($keyword === '$ref' && is_string($value) && ($name = self::defName($value)) !== NULL) {
        $resolved = self::follow($resolved, $name, $value, $root, $ancestors, $followed);
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && self::composes($value)) {
        $conditions = [];
        foreach ($value as $entry) {
          if (!is_array($entry) || isset($entry['if'])) {
            $conditions[] = $entry;
            continue;
          }
          // A reference beside the narrowing is followed in place; a
          // narrowing is resolved on its own and laid over.
          $name = is_string($entry['$ref'] ?? NULL) ? self::defName($entry['$ref']) : NULL;
          if ($name !== NULL && count($entry) === 1) {
            $resolved = self::follow($resolved, $name, $entry['$ref'], $root, $ancestors, $followed);
            continue;
          }
          $inner = $ancestors;
          $resolved = self::merge($resolved, self::resolve($entry, $root, $inner));
        }
        if ($conditions !== []) {
          $resolved = self::merge($resolved, ['allOf' => $conditions]);
        }
        continue;
      }
      $resolved = self::merge($resolved, [$keyword => $value]);
    }
    return $resolved;
  }

  /**
   * Lays a def over a schema in place of a reference to it.
   *
   * @param array $resolved
   *   The schema so far.
   * @param string $name
   *   The def's key.
   * @param string $reference
   *   The `$ref` itself, kept when the def cannot be followed.
   * @param array $root
   *   The root, which holds `$defs`.
   * @param string[] $ancestors
   *   The defs followed on the way to this schema: one of them reached
   *   again is a recursion, and its reference is kept.
   * @param string[] $followed
   *   The defs followed for this schema so far; one of them reached
   *   again is already laid over, and nothing more is done.
   *
   * @return array
   *   The schema, the def laid over it.
   */
  protected static function follow(array $resolved, string $name, string $reference, array $root, array $ancestors, array &$followed): array {
    $def = $root['$defs'][$name] ?? NULL;
    if (in_array($name, $ancestors, TRUE) || !is_array($def)) {
      return self::merge($resolved, ['$ref' => $reference]);
    }
    if (in_array($name, $followed, TRUE)) {
      return $resolved;
    }
    $followed[] = $name;
    $inner = [...$ancestors, $name];
    return self::merge($resolved, self::resolve($def, $root, $inner));
  }

  /**
   * Resolves a schema and every schema inside it.
   *
   * @param array $schema
   *   The schema: the document's root, or any schema in it.
   * @param array|null $root
   *   The root, when $schema is not it.
   * @param string[] $followed
   *   The defs already followed on the way here.
   *
   * @return array
   *   The schema with no local reference left but a recursive one, and,
   *   at the root, no `$defs`.
   */
  public static function inline(array $schema, ?array $root = NULL, array $followed = []): array {
    $at_root = $root === NULL;
    $root ??= $schema;
    $resolved = self::resolve($schema, $root, $followed);
    if ($at_root) {
      unset($resolved['$defs']);
    }
    if (is_array($resolved['properties'] ?? NULL)) {
      foreach ($resolved['properties'] as $name => $property) {
        if (is_array($property)) {
          $resolved['properties'][$name] = self::inline($property, $root, $followed);
        }
      }
    }
    if (is_array($resolved['items'] ?? NULL)) {
      $resolved['items'] = self::inline($resolved['items'], $root, $followed);
    }
    if (is_array($resolved['allOf'] ?? NULL)) {
      foreach ($resolved['allOf'] as $i => $condition) {
        foreach (['then', 'else'] as $branch) {
          if (is_array($condition[$branch] ?? NULL)) {
            $resolved['allOf'][$i][$branch] = self::inline($condition[$branch], $root, $followed);
          }
        }
      }
    }
    return $resolved;
  }

  /**
   * Names the def a site refers to, directly or through its composition.
   *
   * @param array $schema
   *   A site.
   *
   * @return string|null
   *   The def's key under `$defs`, or NULL for a schema that refers to
   *   none.
   */
  public static function refersTo(array $schema): ?string {
    if (is_string($schema['$ref'] ?? NULL)) {
      return self::defName($schema['$ref']);
    }
    foreach (is_array($schema['allOf'] ?? NULL) ? $schema['allOf'] : [] as $entry) {
      if (is_array($entry) && is_string($entry['$ref'] ?? NULL)) {
        return self::defName($entry['$ref']);
      }
    }
    return NULL;
  }

  /**
   * Reads the def a local reference points at.
   *
   * @param string $reference
   *   The `$ref`.
   *
   * @return string|null
   *   The key under `$defs`, or NULL for a reference that is not local.
   */
  public static function defName(string $reference): ?string {
    if (!str_starts_with($reference, ContractEmitter::DEFS)) {
      return NULL;
    }
    $segment = rawurldecode(substr($reference, strlen(ContractEmitter::DEFS)));
    return str_contains($segment, '/') ? NULL : str_replace(['~1', '~0'], ['/', '~'], $segment);
  }

  /**
   * Answers whether an `allOf` composes a reference, not only conditionals.
   *
   * @param array $entries
   *   The `allOf`.
   *
   * @return bool
   *   TRUE when one of its entries is a reference.
   */
  protected static function composes(array $entries): bool {
    foreach ($entries as $entry) {
      if (is_array($entry) && isset($entry['$ref'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lays one schema over another.
   *
   * @param array $base
   *   The schema so far.
   * @param array $over
   *   The schema laid over it.
   *
   * @return array
   *   The merged schema; a keyword already in $base keeps its place.
   */
  protected static function merge(array $base, array $over): array {
    foreach ($over as $keyword => $value) {
      $before = $base[$keyword] ?? NULL;
      if ($keyword === 'properties' && is_array($before) && is_array($value)) {
        foreach ($value as $name => $property) {
          $base[$keyword][$name] = is_array($before[$name] ?? NULL) && is_array($property) ? self::merge($before[$name], $property) : $property;
        }
      }
      elseif ($keyword === 'required' && is_array($before) && is_array($value)) {
        $base[$keyword] = array_values(array_unique([...$before, ...$value]));
      }
      elseif ($keyword === 'allOf' && is_array($before) && is_array($value)) {
        $added = array_filter($value, static fn (mixed $entry): bool => !in_array($entry, $before, FALSE));
        $base[$keyword] = [...$before, ...array_values($added)];
      }
      else {
        $base[$keyword] = $value;
      }
    }
    return $base;
  }

}
