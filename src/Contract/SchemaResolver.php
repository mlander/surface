<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;

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
 * It reads and answers `Schema` values: a caller holding the document's
 * array reads it in with Schema::from(), and the result back with get()
 * or Schemas::arrayed(). A Schema may carry its data (`x-surface`,
 * `x-portable-ui` and the objects inside them) as arrays or as
 * \stdClass objects, as Schema::from() of either kind of decoded JSON
 * gives it: data is read through Schemas::data() and written back in the
 * array form. Inside, schemas are merged as keyword arrays, each a
 * schema's keywords by name, and built again with Schema::from(), which
 * keeps a keyword outside the vocabulary: so every keyword survives a
 * merge, known or not.
 *
 * A reference that leads back into a def being read is left as it is, so
 * a recursive schema ends. A def's own `title` is its id, not a label, so
 * it is not laid over a site. This is the React example's
 * (surface_examples_react) `resolveSchema()` and `inlineSchema()`, in PHP.
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
 * @see \Drupal\surface\Contract\PropertySchema::reference()
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
   * @param \PortableSchema\Builder\Schema $schema
   *   A schema in the document: a property, a `then`, a def.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root schema, which holds `$defs`.
   * @param string[] $followed
   *   The defs already followed on the way here; the ones followed now
   *   are added.
   *
   * @return \PortableSchema\Builder\Schema
   *   The effective schema; a boolean schema as it is.
   */
  public static function resolve(Schema $schema, Schema $root, array &$followed = []): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    // A caller resolves many schemas of one document: its root is restored
    // once for all of them.
    static $restored = [NULL, NULL];
    if ($restored[0] !== $root || $restored[1] === NULL) {
      $restored = [$root, self::restored($root)];
    }
    return Schema::from(self::untitled(self::resolveAt(self::restored($schema), $restored[1], $followed)));
  }

  /**
   * Drops a def's title no site's title replaced.
   *
   * @param array<string, mixed> $resolved
   *   A schema's keywords, resolved.
   *
   * @return array<string, mixed>
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
   * @param \PortableSchema\Builder\Schema $schema
   *   A schema in the document, restored().
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root, restored().
   * @param string[] $followed
   *   The defs already followed on the way here; the ones followed now
   *   are added.
   *
   * @return array<string, mixed>
   *   The effective schema's keywords; a boolean schema has none.
   */
  protected static function resolveAt(Schema $schema, Schema $root, array &$followed = []): array {
    $ancestors = $followed;
    $resolved = [];
    // A frame that mixes structures in holds its own properties beside a
    // bare reference to each structure's def in its allOf.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $frame = is_array($schema->get('properties'));
    $mixed = [];
    foreach ($schema->all() as $keyword => $value) {
      if ($keyword === '$ref' && is_string($value) && ($name = self::defName($value)) !== NULL) {
        $resolved = self::follow($resolved, $name, $value, $root, $ancestors, $followed);
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && self::composes($value)) {
        $conditions = [];
        foreach ($value as $entry) {
          if (!Schemas::isObjectSchema($entry) || $entry->get('if') !== NULL) {
            $conditions[] = $entry;
            continue;
          }
          // A reference beside the narrowing is followed in place; a
          // narrowing is resolved on its own and laid over.
          $reference = $entry->get('$ref');
          $name = is_string($reference) ? self::defName($reference) : NULL;
          $alone = count($entry->all()) === 1;
          if ($name !== NULL && $alone && $frame) {
            // Its members join this frame: what the frame holds besides is
            // not inside the def, so the def is no ancestor of it.
            $outside = $ancestors;
            $mixed[] = self::follow([], $name, (string) $reference, $root, $ancestors, $outside);
            continue;
          }
          if ($name !== NULL && $alone) {
            $resolved = self::follow($resolved, $name, (string) $reference, $root, $ancestors, $followed);
            continue;
          }
          $inner = $ancestors;
          $resolved = self::merged($resolved, self::resolveAt($entry, $root, $inner));
        }
        if ($conditions !== []) {
          $resolved = self::merged($resolved, ['allOf' => $conditions]);
        }
        continue;
      }
      $resolved = self::merged($resolved, [$keyword => $value]);
    }
    foreach (array_reverse($mixed) as $def) {
      $resolved = self::mixIn($resolved, $def);
    }
    if (Schemas::boolean($resolved['unevaluatedProperties'] ?? NULL) === FALSE && isset($resolved['properties']) && !isset($resolved['$ref'])) {
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
   * the owner's. The groups the def declares for its keys join the
   * frame's, the frame's word standing for a group both declare. What else
   * the def says of itself as a whole (the ports it reads) is the
   * structure's, not the frame's.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param array<string, mixed> $frame
   *   The frame's keywords, resolved.
   * @param array<string, mixed> $def
   *   The structure's def's keywords, resolved.
   *
   * @return array<string, mixed>
   *   The frame, the structure's members described in it.
   */
  protected static function mixIn(array $frame, array $def): array {
    $theirs = is_array($def['properties'] ?? NULL) ? $def['properties'] : [];
    $own = is_array($frame['properties'] ?? NULL) ? $frame['properties'] : [];
    $properties = [];
    $ruled = [];
    foreach ($own as $name => $property) {
      $theirs_is_object = Schemas::isObjectSchema($theirs[$name] ?? NULL);
      if (Schemas::boolean($property) === FALSE && $theirs_is_object) {
        // Not applying to these values: no property, as a flat frame says.
        $ruled[] = $name;
        continue;
      }
      $properties[$name] = match (TRUE) {
        !$theirs_is_object => $property,
        Schemas::boolean($property) === TRUE => $theirs[$name],
        Schemas::isObjectSchema($property) => self::merge($theirs[$name], $property),
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
    $groups = self::groups($def);
    if ($groups !== []) {
      $frame = PortableUi::set(Schema::from($frame), ['groups' => Schemas::dataObject(self::groups($frame) + $groups)])->all();
    }
    return $frame;
  }

  /**
   * Reads the groups a frame's `x-portable-ui` declares.
   *
   * @param array<string, mixed> $keywords
   *   The frame's keywords.
   *
   * @return array<string, mixed>
   *   Each group, by id.
   */
  protected static function groups(array $keywords): array {
    return Schemas::data((Schemas::data($keywords[PortableKeywords::UI] ?? NULL) ?? [])['groups'] ?? NULL) ?? [];
  }

  /**
   * Lays a def over a schema in place of a reference to it.
   *
   * @param array<string, mixed> $resolved
   *   The schema's keywords so far.
   * @param string $name
   *   The def's key.
   * @param string $reference
   *   The `$ref` itself, kept when the def cannot be followed.
   * @param \PortableSchema\Builder\Schema $root
   *   The root, which holds `$defs`.
   * @param string[] $ancestors
   *   The defs followed on the way to this schema: one of them reached
   *   again is a recursion, and its reference is kept.
   * @param string[] $followed
   *   The defs followed for this schema so far; one of them reached
   *   again is already laid over, and nothing more is done.
   *
   * @return array<string, mixed>
   *   The schema's keywords, the def laid over them.
   */
  protected static function follow(array $resolved, string $name, string $reference, Schema $root, array $ancestors, array &$followed): array {
    $def = self::def($root, $name);
    if (in_array($name, $ancestors, TRUE) || $def === NULL) {
      return self::merged($resolved, ['$ref' => $reference]);
    }
    // Its id, which names the def, not the key the site labels: held in
    // its place for the site's own title, and dropped when the site has
    // none (untitled()).
    if ($def->has('title')) {
      $def = array_key_exists('title', $resolved) ? $def->without('title') : $def->with('title', self::DEF_TITLE);
    }
    if (in_array($name, $followed, TRUE)) {
      return $resolved;
    }
    $followed[] = $name;
    $inner = [...$ancestors, $name];
    return self::merged($resolved, self::resolveAt($def, $root, $inner));
  }

  /**
   * Gets a def of the document that is an object schema.
   *
   * @param \PortableSchema\Builder\Schema $root
   *   The root, which holds `$defs`.
   * @param string $name
   *   The def's key.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The def, or NULL where the root holds none, or a boolean one.
   */
  protected static function def(Schema $root, string $name): ?Schema {
    $defs = $root->get('$defs');
    $def = is_array($defs) ? ($defs[$name] ?? NULL) : NULL;
    return Schemas::isObjectSchema($def) ? $def : NULL;
  }

  /**
   * Resolves a schema and every schema inside it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema: the document's root, or any schema in it.
   * @param \PortableSchema\Builder\Schema|null $root
   *   The root, when $schema is not it.
   * @param string[] $followed
   *   The defs already followed on the way here.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema with no local reference left but a recursive one, and,
   *   at the root, no `$defs`.
   */
  public static function inline(Schema $schema, ?Schema $root = NULL, array $followed = []): Schema {
    $schema = self::restored($schema);
    return self::inlined($schema, $root === NULL ? $schema : self::restored($root), $followed, $root === NULL);
  }

  /**
   * Resolves a schema and every schema inside it, its markers in place.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema, restored().
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root, restored().
   * @param string[] $followed
   *   The defs already followed on the way here.
   * @param bool $at_root
   *   Whether $schema is the root.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, inline.
   */
  protected static function inlined(Schema $schema, Schema $root, array $followed, bool $at_root): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    $resolved = self::untitled(self::resolveAt($schema, $root, $followed));
    if ($at_root) {
      unset($resolved['$defs']);
    }
    if (is_array($resolved['properties'] ?? NULL)) {
      foreach ($resolved['properties'] as $name => $property) {
        if (Schemas::isObjectSchema($property)) {
          $extension = Schemas::data($property->get(ContractEmitter::EXTENSION));
          if ($extension !== NULL && isset($extension['variants']) && $property->get('anyOf') !== NULL) {
            // The union of its variants: the conditionals state each one.
            $property = $property->without('anyOf');
          }
          $resolved['properties'][$name] = self::inlined($property, $root, $followed, FALSE);
        }
      }
    }
    if (Schemas::isObjectSchema($resolved['items'] ?? NULL)) {
      $resolved['items'] = self::inlined($resolved['items'], $root, $followed, FALSE);
    }
    if (is_array($resolved['allOf'] ?? NULL)) {
      foreach ($resolved['allOf'] as $i => $condition) {
        foreach (['then', 'else'] as $branch) {
          if (Schemas::isObjectSchema($condition) && Schemas::isObjectSchema($condition->get($branch))) {
            $condition = $condition->with($branch, self::inlined($condition->get($branch), $root, $followed, FALSE));
            $resolved['allOf'][$i] = $condition;
          }
        }
      }
    }
    return Schema::from($resolved);
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
   * @param \PortableSchema\Builder\Schema $schema
   *   A schema the emitter wrote, or any schema inside one.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, each marker in place; the same value where nothing moved.
   */
  public static function restored(Schema $schema): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    $keywords = $schema->all();
    $changed = FALSE;
    foreach ($keywords as $keyword => $value) {
      // Written back only where something moved, so a subschema with no
      // marker to put back stays the value it was.
      $maps = in_array($keyword, ['properties', '$defs', 'patternProperties'], TRUE);
      $lists = in_array($keyword, ['allOf', 'anyOf', 'oneOf'], TRUE);
      if (($maps || $lists) && is_array($value)) {
        foreach ($value as $name => $child) {
          if ($child instanceof Schema) {
            $restored = self::restored($child);
            if ($restored !== $child) {
              $keywords[$keyword][$name] = $restored;
              $changed = TRUE;
            }
          }
        }
      }
      elseif (in_array($keyword, self::SINGLE, TRUE) && $value instanceof Schema) {
        $restored = self::restored($value);
        if ($restored !== $value) {
          $keywords[$keyword] = $restored;
          $changed = TRUE;
        }
      }
    }
    $extension = Schemas::data($keywords[ContractEmitter::EXTENSION] ?? NULL);
    if ($extension === NULL || (!isset($extension['rules']) && !isset($extension['within']))) {
      return $changed ? Schema::from($keywords) : $schema;
    }
    foreach (Schemas::data($extension['rules'] ?? NULL) ?? [] as $i) {
      $conditional = is_int($i) && is_array($keywords['allOf'] ?? NULL) ? ($keywords['allOf'][$i] ?? NULL) : NULL;
      if (Schemas::isObjectSchema($conditional)) {
        $marks = Schemas::data($conditional->get(ContractEmitter::EXTENSION)) ?? [];
        $marks['declared'] = TRUE;
        $keywords['allOf'][$i] = $conditional->with(ContractEmitter::EXTENSION, $marks);
      }
    }
    foreach (Schemas::data($extension['within'] ?? NULL) ?? [] as $pointer => $said) {
      $path = array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', substr((string) $pointer, 1)));
      $keywords = self::placed($keywords, $path, $said);
    }
    // Read again: an entry of `within` may have placed the schema's own.
    $extension = Schemas::data($keywords[ContractEmitter::EXTENSION] ?? NULL) ?? [];
    unset($extension['rules'], $extension['within']);
    $keywords[ContractEmitter::EXTENSION] = Schemas::dataObject($extension);
    if ($extension === []) {
      unset($keywords[ContractEmitter::EXTENSION]);
    }
    return Schema::from($keywords);
  }

  /**
   * Sets the `x-surface` of the schema a path leads to.
   *
   * A schema that said nothing but its `x-surface` was left `true` where
   * it stood, or, as a property, left out: either is made an object again.
   * Each step is read by the shape of the keyword it stands in: after a
   * map of schemas a name, after a list of them an index. A path that
   * steps into a keyword holding data leads to no schema: the keywords
   * are left as they are.
   *
   * @param array<string, mixed> $keywords
   *   The owning schema's keywords.
   * @param string[] $path
   *   The path from it, segment by segment.
   * @param mixed $said
   *   The `x-surface`.
   *
   * @return array<string, mixed>
   *   The owning schema's keywords, the `x-surface` in place.
   */
  protected static function placed(array $keywords, array $path, mixed $said): array {
    if ($path === []) {
      $keywords[ContractEmitter::EXTENSION] = $said;
      return $keywords;
    }
    $keyword = (string) array_shift($path);
    $value = $keywords[$keyword] ?? NULL;
    $shape = Keywords::shape($keyword);
    if ($shape === Keywords::VALUE) {
      return $keywords;
    }
    if (($shape === Keywords::SCHEMA_MAP || $shape === Keywords::SCHEMA_LIST) && $path !== []) {
      $segment = (string) array_shift($path);
      $members = is_array($value) ? $value : [];
      $key = $shape === Keywords::SCHEMA_LIST && ctype_digit($segment) ? (int) $segment : $segment;
      $member = $members[$key] ?? NULL;
      $members[$key] = Schema::from(self::placed(Schemas::isObjectSchema($member) ? $member->all() : [], $path, $said));
      $keywords[$keyword] = $members;
      return $keywords;
    }
    $keywords[$keyword] = Schema::from(self::placed(Schemas::isObjectSchema($value) ? $value->all() : [], $path, $said));
    return $keywords;
  }

  /**
   * Names the def a site refers to, directly or through its composition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A site.
   *
   * @return string|null
   *   The def's key under `$defs`, or NULL for a schema that refers to
   *   none.
   */
  public static function refersTo(Schema $schema): ?string {
    $reference = $schema->get('$ref');
    if (is_string($reference)) {
      return self::defName($reference);
    }
    $all = $schema->get('allOf');
    foreach (is_array($all) ? $all : [] as $entry) {
      if (Schemas::isObjectSchema($entry) && is_string($entry->get('$ref'))) {
        return self::defName($entry->get('$ref'));
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
      if (Schemas::isObjectSchema($entry) && $entry->get('$ref') !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lays one schema over another.
   *
   * @param \PortableSchema\Builder\Schema $base
   *   The schema so far.
   * @param \PortableSchema\Builder\Schema $over
   *   The schema laid over it.
   *
   * @return \PortableSchema\Builder\Schema
   *   The merged schema; a keyword already in $base keeps its place.
   */
  public static function merge(Schema $base, Schema $over): Schema {
    return Schema::from(self::merged($base->all(), $over->all()));
  }

  /**
   * Lays one schema's keywords over another's, as merge() says.
   *
   * @param array<string, mixed> $base
   *   The keywords so far.
   * @param array<string, mixed> $over
   *   The keywords laid over them.
   *
   * @return array<string, mixed>
   *   The merged keywords.
   */
  protected static function merged(array $base, array $over): array {
    foreach ($over as $keyword => $value) {
      $before = $base[$keyword] ?? NULL;
      if ($keyword === 'properties' && is_array($before) && is_array($value)) {
        foreach ($value as $name => $property) {
          if (Schemas::boolean($property) === FALSE && Schemas::isObjectSchema($before[$name] ?? NULL)) {
            // Not applying at this site: no property, as a flat frame
            // says it; its frame's `doesNotApply` names it.
            unset($base[$keyword][$name]);
            continue;
          }
          $base[$keyword][$name] = Schemas::isObjectSchema($before[$name] ?? NULL) && Schemas::isObjectSchema($property) ? self::merge($before[$name], $property) : $property;
        }
      }
      elseif ($keyword === 'required' && is_array($before) && is_array($value)) {
        $base[$keyword] = array_values(array_unique([...$before, ...$value]));
      }
      elseif ($keyword === 'allOf' && is_array($before) && is_array($value)) {
        $added = array_filter($value, static fn (mixed $entry): bool => !in_array($entry, $before, FALSE));
        $base[$keyword] = [...$before, ...array_values($added)];
      }
      elseif ($keyword === ContractEmitter::EXTENSION && ($had = Schemas::data($before)) !== NULL && ($says = Schemas::data($value)) !== NULL && isset($had['doesNotApply']) !== isset($says['doesNotApply'])) {
        // What a site says about its key replaces what the def said, but
        // not which keys of the child do not apply here; and a child
        // narrowed at a site laid over what the site says about its key
        // leaves the site's word standing, with which keys of the child do
        // not apply here.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $base[$keyword] = isset($had['doesNotApply']) ? $says + ['doesNotApply' => $had['doesNotApply']] : ['doesNotApply' => $says['doesNotApply']] + $had;
      }
      elseif ($keyword === PortableKeywords::UI && ($had = Schemas::data($before)) !== NULL && ($says = Schemas::data($value)) !== NULL) {
        // A key's hints are replaced whole, and a frame's groups joined: a
        // narrowing declares only the groups its own keys name, so the
        // def's others stay, and the site's word stands for a group both
        // declare.
        // Decision: see docs/decisions.md#the-ui-hints-are-x-portable-ui.
        $had_groups = Schemas::data($had['groups'] ?? NULL);
        $says_groups = Schemas::data($says['groups'] ?? NULL);
        $groups = $had_groups !== NULL && $says_groups !== NULL ? Schemas::dataObject(array_replace($had_groups, $says_groups)) : $says['groups'] ?? $had['groups'] ?? NULL;
        $key = array_diff_key($says, ['groups' => TRUE]);
        $base[$keyword] = Schemas::dataObject(($key !== [] ? $key : array_diff_key($had, ['groups' => TRUE])) + ($groups === NULL ? [] : ['groups' => $groups]));
      }
      else {
        $base[$keyword] = $value;
      }
    }
    return $base;
  }

}
