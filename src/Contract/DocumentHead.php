<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\SurfaceInterface;

/**
 * Writes what sits above a document's properties.
 *
 * ContractEmitter walks a document's frames into its top frame; this
 * class writes the document around it (top()): `$schema` and `$id`, the
 * title and description, the contract's version, how the operation
 * behaves and the resource's version under `x-surface`, `readOnly` when
 * the whole is locked, and each def's title where its structure or shape
 * gave it none. It describes the outputs
 * (outputs()), and lays the whole schema out by the placement rules of
 * contract 2: no `x-surface` below an applicator, and the declared type
 * stated beside a keyword only one type reads (settled()).
 *
 * It holds no state of a document.
 *
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 *
 * @internal
 *
 * @see \Drupal\surface\Contract\ContractEmitter
 */
final class DocumentHead {

  /**
   * The extension keyword, ContractEmitter's.
   */
  protected const EXTENSION = ContractEmitter::EXTENSION;

  /**
   * The keywords whose subschemas carry no `x-surface` in contract 2.
   */
  protected const APPLICATORS = ['allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'not'];

  /**
   * The keywords a schema holds data under, never a subschema.
   */
  protected const DATA = ['const', 'default', 'examples', 'enum', self::EXTENSION];

  /**
   * The JSON type each keyword only one type reads is about.
   *
   * For what a conditional or a site narrows: ajv's strict mode refuses
   * one of these where nothing at that place in the instance states a
   * type, and a type generator reads it as the type's; a narrowing states
   * the declared type beside it.
   */
  protected const TYPED = [
    'minimum' => 'number',
    'maximum' => 'number',
    'exclusiveMinimum' => 'number',
    'exclusiveMaximum' => 'number',
    'multipleOf' => 'number',
    'minLength' => 'string',
    'maxLength' => 'string',
    'pattern' => 'string',
    'properties' => 'object',
    'required' => 'object',
    'additionalProperties' => 'object',
    'unevaluatedProperties' => 'object',
    'minProperties' => 'object',
    'maxProperties' => 'object',
    'items' => 'array',
    'minItems' => 'array',
    'maxItems' => 'array',
    'uniqueItems' => 'array',
  ];

  /**
   * Constructs a DocumentHead.
   *
   * @param \Drupal\surface\Contract\PropertySchema $propertySchema
   *   What describes each output.
   */
  public function __construct(
    protected readonly PropertySchema $propertySchema,
  ) {}

  /**
   * Writes the top of a document's schema around its top frame.
   *
   * `$schema`, `$id` when it is served at one, the title and description,
   * then `x-surface` led by the contract's version, how the operation
   * behaves and the resource's version, and the frame; laid out by the
   * placement rules of the contract's version (settled()).
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $schema
   *   The top frame, its `$defs` written.
   * @param string|\Stringable|null $label
   *   The operation's label.
   * @param array $operation
   *   How the operation behaves, or empty.
   * @param string|null $id
   *   The URI the document is served at, or NULL.
   *
   * @return array
   *   The schema.
   */
  public function top(SurfaceInterface $surface, array $schema, string|\Stringable|null $label, array $operation, ?string $id): array {
    $top = ['$schema' => ContractEmitter::DIALECT];
    if ($id !== NULL) {
      $top['$id'] = $id;
    }
    $schema = $this->lockedWhole($surface, $this->operation($operation, $this->versioned($surface, $schema)));
    $schema[self::EXTENSION] = ['contract' => ContractEmitter::CONTRACT] + $schema[self::EXTENSION];
    if (is_array($schema['$defs'] ?? NULL)) {
      // Each def titled by what its structure or shape is called, and by
      // its key where it is called nothing; a site gives the key its own
      // label beside the reference, as before.
      // Decision: see
      // docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
      foreach ($schema['$defs'] as $key => $def) {
        $title = is_string($def['title'] ?? NULL) && $def['title'] !== '' ? $def['title'] : (string) $key;
        unset($def['title']);
        $schema['$defs'][$key] = ['title' => $title] + $def;
      }
    }
    return $this->settled($top + $this->heading($label, $operation) + $schema);
  }

  /**
   * Lays a document's schema out by the placement rules of contract 2.
   *
   * Written by the one pass that describes every key, the schema carries
   * its markers where that pass reads them; moved here once, at the end:
   *
   * - no `x-surface` below an applicator: a declared rule's conditional
   *   is listed by its index in the owning object's `x-surface.rules`, and
   *   anything else said below one (what a site's narrowing says of a key
   *   inside the child) is said on the owning object, in
   *   `x-surface.within`, by its JSON pointer from that object;
   * - a schema below an applicator that states a keyword only one type
   *   reads, where nothing at that place in the instance states a type
   *   yet, states the declared type beside it, which cannot narrow.
   *
   * The owning object is the nearest schema that is not itself below an
   * applicator: a frame, a property, a def. A reader that wants each
   * marker back where the pass wrote it reads SchemaResolver::inline().
   *
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param array $schema
   *   The document's schema.
   *
   * @return array
   *   The same, laid out.
   */
  protected function settled(array $schema): array {
    return $this->owning($schema, $schema);
  }

  /**
   * Lays out one schema that is not below an applicator, and inside it.
   *
   * @param array $node
   *   The schema.
   * @param array $root
   *   The document's root, which holds `$defs`.
   *
   * @return array
   *   The schema, its applicators carrying no `x-surface`.
   */
  protected function owning(array $node, array $root): array {
    $within = [];
    $rules = [];
    $known = isset($node['type']);
    foreach ($node as $keyword => $value) {
      if (!is_array($value) || in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if (!array_is_list($value)) {
          $node[$keyword] = $this->below($value, '/' . $keyword, $known, [$node], $root, $within);
          continue;
        }
        foreach ($value as $i => $member) {
          if (!is_array($member)) {
            continue;
          }
          if ($keyword === 'allOf' && ($member[self::EXTENSION]['declared'] ?? FALSE) === TRUE) {
            // A declared rule's conditional, stated as written: a client
            // evaluates it whatever the keys it reads hold.
            $rules[] = $i;
            unset($member[self::EXTENSION]['declared']);
            if ($member[self::EXTENSION] === []) {
              unset($member[self::EXTENSION]);
            }
          }
          $node[$keyword][$i] = $this->below($member, '/' . $keyword . '/' . $i, $known, [$node], $root, $within);
        }
        continue;
      }
      if (in_array($keyword, ['properties', '$defs', 'patternProperties'], TRUE)) {
        foreach ($value as $name => $child) {
          if (is_array($child)) {
            $node[$keyword][$name] = $this->owning($child, $root);
          }
        }
        continue;
      }
      if (in_array($keyword, ['items', 'additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE)) {
        $node[$keyword] = $this->owning($value, $root);
      }
    }
    if ($rules !== []) {
      $node[self::EXTENSION]['rules'] = $rules;
    }
    if ($within !== []) {
      $node[self::EXTENSION]['within'] = $within;
    }
    return $node;
  }

  /**
   * Lays out a schema below an applicator, and everything inside it.
   *
   * @param array $node
   *   The schema.
   * @param string $pointer
   *   Its JSON pointer from the owning object.
   * @param bool $known
   *   Whether a type is stated at this place in the instance already, by
   *   a schema around it at the same place.
   * @param array[] $sources
   *   The schemas declaring this place in the instance, nearest first,
   *   to read its declared type from.
   * @param array $root
   *   The document's root.
   * @param array $within
   *   Collects what is said below the owning object, by pointer.
   *
   * @return array|true
   *   The schema, with no `x-surface` and every type it needs; TRUE where
   *   it said nothing but its `x-surface`, which is still a schema, and
   *   one every JSON reader reads back as itself.
   */
  protected function below(array $node, string $pointer, bool $known, array $sources, array $root, array &$within): array|bool {
    if (isset($node[self::EXTENSION])) {
      $within[$pointer] = $node[self::EXTENSION];
      unset($node[self::EXTENSION]);
    }
    $sources = [$node, ...$sources];
    $here = $known || isset($node['type']);
    foreach ($node as $keyword => $value) {
      if (!is_array($value) || in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if (!array_is_list($value)) {
          $node[$keyword] = $this->below($value, $pointer . '/' . $keyword, $here, $sources, $root, $within);
          continue;
        }
        foreach ($value as $i => $member) {
          if (is_array($member)) {
            $node[$keyword][$i] = $this->below($member, $pointer . '/' . $keyword . '/' . $i, $here, $sources, $root, $within);
          }
        }
      }
      elseif ($keyword === 'properties') {
        foreach ($value as $name => $child) {
          if (!is_array($child)) {
            continue;
          }
          $child = $this->below($child, $pointer . '/properties/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), FALSE, $this->declaredAt($sources, 'properties', (string) $name, $root), $root, $within);
          if ($child === TRUE) {
            // It said nothing but its `x-surface`, now said above: a
            // property that narrows nothing is no property here.
            unset($node[$keyword][$name]);
          }
          else {
            $node[$keyword][$name] = $child;
          }
        }
        if ($node[$keyword] === []) {
          unset($node[$keyword]);
        }
      }
      elseif ($keyword === 'items') {
        $node[$keyword] = $this->below($value, $pointer . '/items', FALSE, $this->declaredAt($sources, 'items', NULL, $root), $root, $within);
      }
      elseif (in_array($keyword, ['additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE)) {
        $node[$keyword] = $this->below($value, $pointer . '/' . $keyword, FALSE, [], $root, $within);
      }
    }
    if ($node === []) {
      return TRUE;
    }
    if (!$known && !isset($node['type']) && array_intersect_key($node, self::TYPED) !== []) {
      $type = $this->declaredType($sources, $root);
      if ($type !== NULL) {
        $node = ['type' => $type] + $node;
      }
    }
    return $node;
  }

  /**
   * Reads the type declared for a place in the instance.
   *
   * @param array[] $sources
   *   The schemas declaring it, nearest first.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way, so a reference back ends.
   *
   * @return string|array|null
   *   The first type one of them states, following its references; NULL
   *   when none does.
   */
  protected function declaredType(array $sources, array $root, array $seen = []): string|array|null {
    foreach ($sources as $source) {
      if (isset($source['type'])) {
        return $source['type'];
      }
      $followed = [...$seen, ...$this->references($source)];
      $type = $this->declaredType($this->referred($source, $root, $seen), $root, $followed);
      if ($type !== NULL) {
        return $type;
      }
    }
    return NULL;
  }

  /**
   * Reads what the schemas declaring a place declare one step inside it.
   *
   * @param array[] $sources
   *   The schemas declaring the place, nearest first.
   * @param string $keyword
   *   The keyword: `properties` or `items`.
   * @param string|null $name
   *   The property, for `properties`.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return array[]
   *   The schemas declaring the place inside, nearest first.
   */
  protected function declaredAt(array $sources, string $keyword, ?string $name, array $root, array $seen = []): array {
    $found = [];
    foreach ($sources as $source) {
      $inside = $name === NULL ? ($source[$keyword] ?? NULL) : (is_array($source[$keyword] ?? NULL) ? ($source[$keyword][$name] ?? NULL) : NULL);
      if (is_array($inside)) {
        $found[] = $inside;
      }
      $followed = [...$seen, ...$this->references($source)];
      array_push($found, ...$this->declaredAt($this->referred($source, $root, $seen), $keyword, $name, $root, $followed));
    }
    return $found;
  }

  /**
   * Lists the local references a schema makes, beside it or composed.
   *
   * @param array $source
   *   The schema.
   *
   * @return string[]
   *   The def keys.
   */
  protected function references(array $source): array {
    $references = [];
    foreach ([$source, ...(is_array($source['allOf'] ?? NULL) ? $source['allOf'] : [])] as $part) {
      $name = is_array($part) && is_string($part['$ref'] ?? NULL) ? SchemaResolver::defName($part['$ref']) : NULL;
      if ($name !== NULL) {
        $references[] = $name;
      }
    }
    return $references;
  }

  /**
   * Reads the defs a schema refers to, not yet followed.
   *
   * @param array $source
   *   The schema.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return array[]
   *   The defs.
   */
  protected function referred(array $source, array $root, array $seen): array {
    $defs = [];
    foreach (array_diff($this->references($source), $seen) as $name) {
      if (is_array($root['$defs'][$name] ?? NULL)) {
        $defs[] = $root['$defs'][$name];
      }
    }
    return $defs;
  }

  /**
   * Heads a document: its title, and its description when there is one.
   *
   * The title is the operation's label. The description is the
   * operation's, what a call does to what, which a client choosing
   * between operations reads first; a document asked for in no operation,
   * or for one that says none, has none.
   *
   * @param string|\Stringable|null $label
   *   The operation's label.
   * @param array $operation
   *   How the operation behaves (OperationDefinition::facts()), or empty.
   *
   * @return array<string, string>
   *   `title` and `description`, each only when there is one.
   */
  protected function heading(string|\Stringable|null $label, array $operation): array {
    $heading = $label === NULL ? [] : ['title' => PlainText::of($label)];
    if (isset($operation['description']) && (string) $operation['description'] !== '') {
      $heading['description'] = PlainText::of($operation['description']);
    }
    return $heading;
  }

  /**
   * States how the operation behaves, at the top of the schema.
   *
   * Under the top's `x-surface`, first, beside the title that is the
   * operation's label: a client that reads the contract directly, with no
   * tool listing, learns whether a call changes nothing, may destroy what
   * is there, creates, and is safe to repeat. Whatever else the top frame
   * says under `x-surface` follows it.
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @param array $operation
   *   The facts, or empty for none.
   * @param array $schema
   *   The top frame.
   *
   * @return array
   *   The top frame, `x-surface` first when there are facts.
   */
  protected function operation(array $operation, array $schema): array {
    if ($operation === []) {
      return $schema;
    }
    // `kind: read` says it, and JSON Schema's `readOnly` at the same top
    // means locked whole. The attribute keeps it, for the tool.
    // Decision: see docs/decisions.md#contract-2.
    unset($operation['readOnly']);
    $extension = [self::EXTENSION => ['operation' => $operation] + ($schema[self::EXTENSION] ?? [])];
    unset($schema[self::EXTENSION]);
    return $extension + $schema;
  }

  /**
   * Stamps which version of its stored values a frame describes.
   *
   * At the top always, first under `x-surface` after how the operation
   * behaves, so a client compares the number it asked for without knowing
   * a default; on a def only above 1, since a def at 1 has said nothing
   * since values were first stored, and its absence reads as 1.
   *
   * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface the frame was described from.
   * @param array $schema
   *   The frame.
   * @param bool $top
   *   Whether it is the top of a document rather than a def.
   *
   * @return array
   *   The frame, stamped.
   */
  public function versioned(SurfaceInterface $surface, array $schema, bool $top = TRUE): array {
    if (!$top && $surface->version() === 1) {
      return $schema;
    }
    $schema[self::EXTENSION] = ['version' => $surface->version()] + ($schema[self::EXTENSION] ?? []);
    if (!$top) {
      return $schema;
    }
    $extension = [self::EXTENSION => $schema[self::EXTENSION]];
    unset($schema[self::EXTENSION]);
    return $extension + $schema;
  }

  /**
   * Says at the top that the whole is locked, when an access answer said so.
   *
   * Every key is already `readOnly`; the top says it too, so a client
   * reading the document offers no submit.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $schema
   *   The top frame.
   *
   * @return array
   *   The top frame, `readOnly` when locked whole.
   */
  protected function lockedWhole(SurfaceInterface $surface, array $schema): array {
    if ($surface->lockedWhole() === NULL) {
      return $schema;
    }
    return ['readOnly' => TRUE] + $schema;
  }

  /**
   * Describes the outputs a data surface declares, if it declares any.
   *
   * Outputs are never refined, never rendered and never locked, so they
   * carry no `x-surface`.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk: its cacheability collects what the description depends on.
   *
   * @return array|null
   *   The output schema, or NULL for a data surface with no outputs.
   */
  public function outputs(SurfaceInterface $surface, Emission $emission): ?array {
    $properties = [];
    $required = [];
    $emission = $emission->withoutStale();
    foreach ($surface->getOutputDefinitions() as $name => $definition) {
      [$schema] = $this->propertySchema->property($definition, NULL, NULL, (string) $name, FALSE, [], $emission);
      $properties[(string) $name] = $this->propertySchema->withoutExtension($schema);
      if ($definition->isRequired()) {
        $required[] = (string) $name;
      }
    }
    if ($properties === []) {
      return NULL;
    }
    return [
      '$schema' => ContractEmitter::DIALECT,
      'type' => 'object',
      'properties' => $properties,
    ] + ($required === [] ? [] : ['required' => $required]);
  }

}
