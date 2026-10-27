<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * Reads a schema the contract emitter wrote as one inline schema.
 *
 * The emitter writes each child once, under the root's `$defs`, and
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
 *   union, and `allOf` the concatenation;
 * - a frame's own `properties` beside a bare reference in its `allOf` is
 *   a frame that mixes a structure in: the def is laid under the frame,
 *   each member keeping its place in the frame, and the frame, every key
 *   now its own, is closed with `additionalProperties` in place of
 *   `unevaluatedProperties`.
 *
 * A reference that leads back into a def being read is left as it is, so
 * a recursive schema ends. A def's own `title` is its id, not a label, so
 * it is not laid over a site. This is data_surface_react's
 * `resolveSchema()` and `inlineSchema()`, in PHP.
 *
 * The inline reading is the emitter's own, before the placement rules of
 * the contract's version moved its markers (DocumentHead::settled()):
 * restored() puts each `x-surface.within` entry back where it stands and
 * marks each conditional `x-surface.rules` lists `x-surface.declared`, a
 * mark of the inline reading alone, which no document carries, since
 * composition moves conditionals and their indexes with them, and
 * a key's union of its variants is left to the conditionals, which state
 * each one exactly.
 *
 * @see \Drupal\data_surface\Contract\PropertySchema::reference()
 */
final class SchemaResolver {

  /**
   * Stands where a def's title stood, until a site's own replaces it.
   */
  protected const DEF_TITLE = "\0def-title";

  /**
   * The keywords that hold one subschema, not a list or a map of them.
   */
  protected const SINGLE = [
    'if',
    'then',
    'else',
    'not',
    'items',
    'additionalProperties',
    'unevaluatedProperties',
    'contains',
    'propertyNames',
  ];

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
    // A caller resolves many schemas of one document: its root is restored
    // once for all of them.
    static $restored = [NULL, []];
    if ($restored[0] !== $root) {
      $restored = [$root, self::restored($root)];
    }
    return self::untitled(self::resolveAt(self::restored($schema), $restored[1], $followed));
  }

  /**
   * Drops a def's title no site's title replaced.
   *
   * @param array $resolved
   *   A schema, resolved.
   *
   * @return array
   *   The same, with no def's title left.
   */
  protected static function untitled(array $resolved): array {
    if (($resolved['title'] ?? NULL) === self::DEF_TITLE) {
      unset($resolved['title']);
    }
    return $resolved;
  }

  /**
   * Resolves one schema's references, its markers in place.
   *
   * @param array $schema
   *   A schema in the document, restored().
   * @param array $root
   *   The document's root, restored().
   * @param string[] $followed
   *   The defs already followed on the way here; the ones followed now
   *   are added.
   *
   * @return array
   *   The effective schema.
   */
  protected static function resolveAt(array $schema, array $root, array &$followed = []): array {
    $ancestors = $followed;
    $resolved = [];
    // A frame that mixes structures in holds its own properties beside a
    // bare reference to each structure's def in its allOf.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $frame = is_array($schema['properties'] ?? NULL) || ($schema['properties'] ?? NULL) instanceof \stdClass;
    $mixed = [];
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
          if ($name !== NULL && count($entry) === 1 && $frame) {
            // Its members join this frame: what the frame holds besides is
            // not inside the def, so the def is no ancestor of it.
            $alone = $ancestors;
            $mixed[] = self::follow([], $name, $entry['$ref'], $root, $ancestors, $alone);
            continue;
          }
          if ($name !== NULL && count($entry) === 1) {
            $resolved = self::follow($resolved, $name, $entry['$ref'], $root, $ancestors, $followed);
            continue;
          }
          $inner = $ancestors;
          $resolved = self::merge($resolved, self::resolveAt($entry, $root, $inner));
        }
        if ($conditions !== []) {
          $resolved = self::merge($resolved, ['allOf' => $conditions]);
        }
        continue;
      }
      $resolved = self::merge($resolved, [$keyword => $value]);
    }
    foreach (array_reverse($mixed) as $def) {
      $resolved = self::mixIn($resolved, $def);
    }
    if (($resolved['unevaluatedProperties'] ?? NULL) === FALSE && isset($resolved['properties']) && !isset($resolved['$ref'])) {
      // Every key is in the frame's own properties now, so it closes them.
      $closed = [];
      foreach ($resolved as $keyword => $value) {
        $closed[$keyword === 'unevaluatedProperties' ? 'additionalProperties' : $keyword] = $value;
      }
      $resolved = $closed;
    }
    return $resolved;
  }

  /**
   * Lays a frame over the def of a structure it mixes in.
   *
   * Each member keeps its place in the frame's properties, the def's
   * schema for it with what the frame narrows laid over (`true` narrows
   * nothing; `false`, not applying here, leaves it out, as a flat frame
   * does); the def's `required` joins the frame's, in the frame's order,
   * and its conditionals come first, as its rules and refiners run before
   * the owner's. What the def says of itself as a whole (the ports it
   * reads) is the structure's, not the frame's.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param array $frame
   *   The frame, resolved.
   * @param array $def
   *   The structure's def, resolved.
   *
   * @return array
   *   The frame, the structure's members described in it.
   */
  protected static function mixIn(array $frame, array $def): array {
    $theirs = is_array($def['properties'] ?? NULL) ? $def['properties'] : [];
    $own = is_array($frame['properties'] ?? NULL) ? $frame['properties'] : [];
    $properties = [];
    $ruled = [];
    foreach ($own as $name => $property) {
      if ($property === FALSE && is_array($theirs[$name] ?? NULL)) {
        // Not applying to these values: no property, as a flat frame says.
        $ruled[] = $name;
        continue;
      }
      $properties[$name] = match (TRUE) {
        !is_array($theirs[$name] ?? NULL) => $property,
        $property === TRUE => $theirs[$name],
        is_array($property) => self::merge($theirs[$name], $property),
        default => $property,
      };
    }
    $properties += array_diff_key($theirs, array_flip($ruled));
    $frame['properties'] = $properties;
    // In the frame's order, as a flat frame lists what it requires.
    $required = array_diff([...($frame['required'] ?? []), ...($def['required'] ?? [])], $ruled);
    $required = array_values(array_intersect(array_keys($properties), $required));
    if ($required !== []) {
      $frame['required'] = $required;
    }
    $conditions = [
      ...(is_array($def['allOf'] ?? NULL) ? $def['allOf'] : []),
      ...(is_array($frame['allOf'] ?? NULL) ? $frame['allOf'] : []),
    ];
    if ($conditions !== []) {
      $frame['allOf'] = $conditions;
    }
    return $frame;
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
    // Its id, which names the def, not the key the site labels: held in
    // its place for the site's own title, and dropped when the site has
    // none (untitled()).
    if (array_key_exists('title', $def)) {
      if (array_key_exists('title', $resolved)) {
        unset($def['title']);
      }
      else {
        $def['title'] = self::DEF_TITLE;
      }
    }
    if (in_array($name, $followed, TRUE)) {
      return $resolved;
    }
    $followed[] = $name;
    $inner = [...$ancestors, $name];
    return self::merge($resolved, self::resolveAt($def, $root, $inner));
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
    $schema = self::restored($schema);
    return self::inlined($schema, $root === NULL ? $schema : self::restored($root), $followed, $root === NULL);
  }

  /**
   * Resolves a schema and every schema inside it, its markers in place.
   *
   * @param array $schema
   *   The schema, restored().
   * @param array $root
   *   The document's root, restored().
   * @param string[] $followed
   *   The defs already followed on the way here.
   * @param bool $at_root
   *   Whether $schema is the root.
   *
   * @return array
   *   The schema, inline.
   */
  protected static function inlined(array $schema, array $root, array $followed, bool $at_root): array {
    $resolved = self::untitled(self::resolveAt($schema, $root, $followed));
    if ($at_root) {
      unset($resolved['$defs']);
    }
    if (is_array($resolved['properties'] ?? NULL)) {
      foreach ($resolved['properties'] as $name => $property) {
        if (is_array($property)) {
          if (isset($property[ContractEmitter::EXTENSION]['variants'], $property['anyOf'])) {
            // The union of its variants: the conditionals state each one.
            unset($property['anyOf']);
          }
          $resolved['properties'][$name] = self::inlined($property, $root, $followed, FALSE);
        }
      }
    }
    if (is_array($resolved['items'] ?? NULL)) {
      $resolved['items'] = self::inlined($resolved['items'], $root, $followed, FALSE);
    }
    if (is_array($resolved['allOf'] ?? NULL)) {
      foreach ($resolved['allOf'] as $i => $condition) {
        foreach (['then', 'else'] as $branch) {
          if (is_array($condition[$branch] ?? NULL)) {
            $resolved['allOf'][$i][$branch] = self::inlined($condition[$branch], $root, $followed, FALSE);
          }
        }
      }
    }
    return $resolved;
  }

  /**
   * Puts each marker back where the emitter's pass wrote it.
   *
   * The wire keeps `x-surface` off every schema below an applicator: the
   * owning object lists its declared rules' conditionals by index in
   * `x-surface.rules`, and says what is said below it in
   * `x-surface.within`, by JSON pointer from itself. This reads both back
   * into place, `x-surface.declared` on each rule's conditional (the
   * inline reading's own mark, written in no document) and each entry of
   * `within` as the `x-surface` of the schema it points at, and drops the
   * two lists. Applied twice, it changes nothing more.
   *
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param array $schema
   *   A schema the emitter wrote, or any schema inside one.
   *
   * @return array
   *   The same, each marker in place.
   */
  public static function restored(array $schema): array {
    foreach ($schema as $keyword => $value) {
      if (!is_array($value)) {
        continue;
      }
      if (in_array($keyword, ['properties', '$defs', 'patternProperties'], TRUE)) {
        foreach ($value as $name => $child) {
          if (is_array($child)) {
            $schema[$keyword][$name] = self::restored($child);
          }
        }
      }
      elseif (in_array($keyword, ['allOf', 'anyOf', 'oneOf'], TRUE) && array_is_list($value)) {
        foreach ($value as $i => $member) {
          if (is_array($member)) {
            $schema[$keyword][$i] = self::restored($member);
          }
        }
      }
      elseif (in_array($keyword, self::SINGLE, TRUE)) {
        $schema[$keyword] = self::restored($value);
      }
    }
    $extension = $schema[ContractEmitter::EXTENSION] ?? NULL;
    if (!is_array($extension) || (!isset($extension['rules']) && !isset($extension['within']))) {
      return $schema;
    }
    foreach (is_array($extension['rules'] ?? NULL) ? $extension['rules'] : [] as $i) {
      if (is_int($i) && is_array($schema['allOf'][$i] ?? NULL)) {
        $schema['allOf'][$i][ContractEmitter::EXTENSION]['declared'] = TRUE;
      }
    }
    foreach (is_array($extension['within'] ?? NULL) ? $extension['within'] : [] as $pointer => $said) {
      $path = array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', substr((string) $pointer, 1)));
      $schema = self::placed($schema, $path, $said);
    }
    unset($schema[ContractEmitter::EXTENSION]['rules'], $schema[ContractEmitter::EXTENSION]['within']);
    if ($schema[ContractEmitter::EXTENSION] === []) {
      unset($schema[ContractEmitter::EXTENSION]);
    }
    return $schema;
  }

  /**
   * Sets the `x-surface` of the schema a path leads to.
   *
   * A schema that said nothing but its `x-surface` was left `true` where
   * it stood, or, as a property, left out: either is made an object again.
   *
   * @param array $schema
   *   The owning schema.
   * @param string[] $path
   *   The path from it, segment by segment.
   * @param mixed $said
   *   The `x-surface`.
   *
   * @return array
   *   The owning schema, the `x-surface` in place.
   */
  protected static function placed(array $schema, array $path, mixed $said): array {
    if ($path === []) {
      $schema[ContractEmitter::EXTENSION] = $said;
      return $schema;
    }
    $segment = array_shift($path);
    $key = ctype_digit($segment) && array_is_list($schema) ? (int) $segment : $segment;
    $inner = $schema[$key] ?? [];
    $schema[$key] = self::placed(is_array($inner) ? $inner : [], $path, $said);
    return $schema;
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
          if ($property === FALSE && is_array($before[$name] ?? NULL)) {
            // Not applying at this site: no property, as a flat frame
            // says it; its frame's `doesNotApply` names it.
            unset($base[$keyword][$name]);
            continue;
          }
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
      elseif ($keyword === ContractEmitter::EXTENSION && is_array($before) && is_array($value) && isset($before['doesNotApply']) && !isset($value['doesNotApply'])) {
        // What a site says about its key replaces what the def said, but
        // not which keys of the child do not apply here.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $base[$keyword] = $value + ['doesNotApply' => $before['doesNotApply']];
      }
      elseif ($keyword === ContractEmitter::EXTENSION && is_array($before) && is_array($value) && !isset($before['doesNotApply']) && isset($value['doesNotApply'])) {
        // A child narrowed at a site laid over what the site says about
        // its key: the site's word stands, with which keys of the child
        // do not apply here.
        $base[$keyword] = ['doesNotApply' => $value['doesNotApply']] + $before;
      }
      else {
        $base[$keyword] = $value;
      }
    }
    return $base;
  }

}
