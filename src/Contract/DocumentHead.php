<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

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
  protected const DATA = [
    'const',
    'default',
    'examples',
    'enum',
    self::EXTENSION,
    PortableKeywords::CHECK,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
  ];

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
   * @param \PortableSchema\Builder\Schema $schema
   *   The top frame, its `$defs` written.
   * @param string|\Stringable|null $label
   *   The operation's label.
   * @param array $operation
   *   How the operation behaves, or empty.
   * @param string|null $id
   *   The URI the document is served at, or NULL.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  public function top(SurfaceInterface $surface, Schema $schema, string|\Stringable|null $label, array $operation, ?string $id): Schema {
    $top = ['$schema' => ContractEmitter::DIALECT];
    if ($id !== NULL) {
      $top['$id'] = $id;
    }
    $schema = $this->lockedWhole($surface, $this->operation($operation, $this->versioned($surface, $schema)));
    $schema = $schema->with(self::EXTENSION, ['contract' => ContractEmitter::CONTRACT] + (array) $schema->get(self::EXTENSION));
    $defs = $schema->get('$defs');
    if (is_array($defs)) {
      // Each def titled by what its structure or shape is called, and by
      // its key where it is called nothing; a site gives the key its own
      // label beside the reference, as before.
      // Decision: see
      // docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
      foreach ($defs as $key => $def) {
        $title = $def->get('title');
        $title = is_string($title) && $title !== '' ? $title : (string) $key;
        $defs[$key] = Schemas::first($def->without('title'), ['title' => $title]);
      }
      $schema = $schema->with('$defs', $defs);
    }
    return $this->settled(Schemas::first($schema, $top + $this->heading($label, $operation)));
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The document's schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, laid out.
   */
  protected function settled(Schema $schema): Schema {
    return $this->owning($schema, $schema);
  }

  /**
   * Lays out one schema that is not below an applicator, and inside it.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The schema.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root, which holds `$defs`.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its applicators carrying no `x-surface`.
   */
  protected function owning(Schema $node, Schema $root): Schema {
    $within = [];
    $rules = [];
    $known = $node->has('type');
    foreach ($node->all() as $keyword => $value) {
      if (in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if ($value instanceof Schema) {
          if (!$value->isBoolean()) {
            $node = $node->with($keyword, $this->below($value, '/' . $keyword, $known, [$node], $root, $within));
          }
          continue;
        }
        if (!is_array($value)) {
          continue;
        }
        foreach ($value as $i => $member) {
          if ($member->isBoolean()) {
            continue;
          }
          $extension = $member->get(self::EXTENSION);
          if ($keyword === 'allOf' && is_array($extension) && ($extension['declared'] ?? FALSE) === TRUE) {
            // A declared rule's conditional, stated as written: a client
            // evaluates it whatever the keys it reads hold.
            $rules[] = $i;
            unset($extension['declared']);
            $member = $extension === [] ? $member->without(self::EXTENSION) : $member->with(self::EXTENSION, $extension);
          }
          $value[$i] = $this->below($member, '/' . $keyword . '/' . $i, $known, [$node], $root, $within);
        }
        $node = $node->with($keyword, $value);
        continue;
      }
      if (in_array($keyword, ['properties', '$defs', 'patternProperties'], TRUE) && is_array($value)) {
        foreach ($value as $name => $child) {
          if (!$child->isBoolean()) {
            $value[$name] = $this->owning($child, $root);
          }
        }
        $node = $node->with($keyword, $value);
        continue;
      }
      if (in_array($keyword, ['items', 'additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE) && $value instanceof Schema && !$value->isBoolean()) {
        $node = $node->with($keyword, $this->owning($value, $root));
      }
    }
    if ($rules === [] && $within === []) {
      return $node;
    }
    $extension = (array) $node->get(self::EXTENSION);
    if ($rules !== []) {
      $extension['rules'] = $rules;
    }
    if ($within !== []) {
      $extension['within'] = $within;
    }
    return $node->with(self::EXTENSION, $extension);
  }

  /**
   * Lays out a schema below an applicator, and everything inside it.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The schema.
   * @param string $pointer
   *   Its JSON pointer from the owning object.
   * @param bool $known
   *   Whether a type is stated at this place in the instance already, by
   *   a schema around it at the same place.
   * @param \PortableSchema\Builder\Schema[] $sources
   *   The schemas declaring this place in the instance, nearest first,
   *   to read its declared type from.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root.
   * @param array $within
   *   Collects what is said below the owning object, by pointer.
   *
   * @return \PortableSchema\Builder\Schema|true
   *   The schema, with no `x-surface` and every type it needs; TRUE where
   *   it said nothing but its `x-surface` (directly or in the properties
   *   it holds), which is still a schema, and one every JSON reader reads
   *   back as itself. A schema empty from the start is kept as `{}`.
   */
  protected function below(Schema $node, string $pointer, bool $known, array $sources, Schema $root, array &$within): Schema|bool {
    if ($node->all() === []) {
      // An empty schema is written as given, `{}`.
      return $node;
    }
    if ($node->has(self::EXTENSION)) {
      $within[$pointer] = $node->get(self::EXTENSION);
      $node = $node->without(self::EXTENSION);
    }
    $sources = [$node, ...$sources];
    $here = $known || $node->has('type');
    foreach ($node->all() as $keyword => $value) {
      if (in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if ($value instanceof Schema) {
          if (!$value->isBoolean()) {
            $node = $node->with($keyword, $this->below($value, $pointer . '/' . $keyword, $here, $sources, $root, $within));
          }
          continue;
        }
        if (is_array($value)) {
          foreach ($value as $i => $member) {
            if (!$member->isBoolean()) {
              $value[$i] = $this->below($member, $pointer . '/' . $keyword . '/' . $i, $here, $sources, $root, $within);
            }
          }
          $node = $node->with($keyword, $value);
        }
      }
      elseif ($keyword === 'properties' && is_array($value)) {
        foreach ($value as $name => $child) {
          if ($child->isBoolean()) {
            continue;
          }
          $child = $this->below($child, $pointer . '/properties/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), FALSE, $this->declaredAt($sources, 'properties', (string) $name, $root), $root, $within);
          if ($child === TRUE) {
            // It said nothing but its `x-surface`, now said above: a
            // property that narrows nothing is no property here.
            unset($value[$name]);
          }
          else {
            $value[$name] = $child;
          }
        }
        $node = $value === [] ? $node->without($keyword) : $node->with($keyword, $value);
      }
      elseif ($keyword === 'items' && $value instanceof Schema && !$value->isBoolean()) {
        $node = $node->with($keyword, $this->below($value, $pointer . '/items', FALSE, $this->declaredAt($sources, 'items', NULL, $root), $root, $within));
      }
      elseif (in_array($keyword, ['additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE) && $value instanceof Schema && !$value->isBoolean()) {
        $node = $node->with($keyword, $this->below($value, $pointer . '/' . $keyword, FALSE, [], $root, $within));
      }
    }
    if ($node->all() === []) {
      return TRUE;
    }
    if (!$known && !$node->has('type') && array_intersect_key($node->all(), self::TYPED) !== []) {
      $type = $this->declaredType($sources, $root);
      if ($type !== NULL) {
        $node = Schemas::first($node, ['type' => $type]);
      }
    }
    return $node;
  }

  /**
   * Reads the type declared for a place in the instance.
   *
   * @param \PortableSchema\Builder\Schema[] $sources
   *   The schemas declaring it, nearest first.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way, so a reference back ends.
   *
   * @return string|array|null
   *   The first type one of them states, following its references; NULL
   *   when none does.
   */
  protected function declaredType(array $sources, Schema $root, array $seen = []): string|array|null {
    foreach ($sources as $source) {
      if ($source->get('type') !== NULL) {
        return $source->get('type');
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
   * @param \PortableSchema\Builder\Schema[] $sources
   *   The schemas declaring the place, nearest first.
   * @param string $keyword
   *   The keyword: `properties` or `items`.
   * @param string|null $name
   *   The property, for `properties`.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return \PortableSchema\Builder\Schema[]
   *   The schemas declaring the place inside, nearest first.
   */
  protected function declaredAt(array $sources, string $keyword, ?string $name, Schema $root, array $seen = []): array {
    $found = [];
    foreach ($sources as $source) {
      $value = $source->get($keyword);
      $inside = $name === NULL ? $value : (is_array($value) ? ($value[$name] ?? NULL) : NULL);
      if ($inside instanceof Schema && !$inside->isBoolean()) {
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
   * @param \PortableSchema\Builder\Schema $source
   *   The schema.
   *
   * @return string[]
   *   The def keys.
   */
  protected function references(Schema $source): array {
    $references = [];
    $composed = $source->get('allOf');
    foreach ([$source, ...(is_array($composed) ? $composed : [])] as $part) {
      $reference = $part->get('$ref');
      $name = is_string($reference) ? SchemaResolver::defName($reference) : NULL;
      if ($name !== NULL) {
        $references[] = $name;
      }
    }
    return $references;
  }

  /**
   * Reads the defs a schema refers to, not yet followed.
   *
   * @param \PortableSchema\Builder\Schema $source
   *   The schema.
   * @param \PortableSchema\Builder\Schema $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return \PortableSchema\Builder\Schema[]
   *   The defs.
   */
  protected function referred(Schema $source, Schema $root, array $seen): array {
    $defs = [];
    $declared = $root->get('$defs');
    foreach (array_diff($this->references($source), $seen) as $name) {
      $def = is_array($declared) ? ($declared[$name] ?? NULL) : NULL;
      if ($def instanceof Schema && !$def->isBoolean()) {
        $defs[] = $def;
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The top frame.
   *
   * @return \PortableSchema\Builder\Schema
   *   The top frame, `x-surface` first when there are facts.
   */
  protected function operation(array $operation, Schema $schema): Schema {
    if ($operation === []) {
      return $schema;
    }
    // `kind: read` says it, and JSON Schema's `readOnly` at the same top
    // means locked whole. The attribute keeps it, for the tool.
    // Decision: see docs/decisions.md#contract-2.
    unset($operation['readOnly']);
    $extension = ['operation' => $operation] + (array) $schema->get(self::EXTENSION);
    return Schemas::first($schema->without(self::EXTENSION), [self::EXTENSION => $extension]);
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The frame.
   * @param bool $top
   *   Whether it is the top of a document rather than a def.
   *
   * @return \PortableSchema\Builder\Schema
   *   The frame, stamped.
   */
  public function versioned(SurfaceInterface $surface, Schema $schema, bool $top = TRUE): Schema {
    if (!$top && $surface->version() === 1) {
      return $schema;
    }
    $extension = ['version' => $surface->version()] + (array) $schema->get(self::EXTENSION);
    if (!$top) {
      return $schema->with(self::EXTENSION, $extension);
    }
    return Schemas::first($schema->without(self::EXTENSION), [self::EXTENSION => $extension]);
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The top frame.
   *
   * @return \PortableSchema\Builder\Schema
   *   The top frame, `readOnly` when locked whole.
   */
  protected function lockedWhole(SurfaceInterface $surface, Schema $schema): Schema {
    if ($surface->lockedWhole() === NULL) {
      return $schema;
    }
    return Schemas::first($schema, ['readOnly' => TRUE]);
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
   * @return \PortableSchema\Builder\Schema|null
   *   The output schema, or NULL for a data surface with no outputs.
   */
  public function outputs(SurfaceInterface $surface, Emission $emission): ?Schema {
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
    $outputs = Schema::keywords([
      '$schema' => ContractEmitter::DIALECT,
      'type' => 'object',
      'properties' => $properties,
    ]);
    return $required === [] ? $outputs : $outputs->with('required', $required);
  }

}
