<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * Writes the schema of one value, or of a list of values, from its facts.
 *
 * The facts are what a definition of the value would hold in its array
 * (`type`, `label`, `description`, `required`, `constraints`, `settings`,
 * `default_value`, `examples`, `secret` and the keys Surface's metadata
 * stores), and the schema is the one DefinitionSchema::of() reads from
 * such a definition: the same keywords in the same order, the same checks
 * and messages, the same MODEL members. No definition is made. A list's
 * facts are its own, and its item's are given beside them.
 *
 * What it writes is for a DataDefinition of a type LeafTypes has a row
 * for (DEFAULT_CONSTRAINTS), and a ListDataDefinition of such items: the
 * classes KeywordReader reads an author's value key into. The merged
 * constraint list of either is derived from the facts
 * (NodeFacts::derived()), so the schema holds no `constraints` member, as
 * of() writes none for these classes.
 *
 * Mirrors DefinitionSchema::build() for those two classes, step for step,
 * so that DefinitionSchema::same() holds between the two writes. Phase 2
 * of the build that moves the builder to schemas lets build() read a
 * DataDefinition's array through here, so one function writes both.
 *
 * Decision: see docs/decisions.md#authoring-takes-a-schema.
 *
 * @internal
 */
final class ValueSchema {

  /**
   * Writes the schema of one value from its facts.
   *
   * @param array $facts
   *   What its definition's array would hold, `type` among it.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its schema, as DefinitionSchema::of() reads it.
   */
  public static function value(array $facts): Schema {
    $type = (string) ($facts['type'] ?? '');
    $members = static::members($facts, $type);
    $spelled = static::spelled($facts, $type, $members);
    if (isset($spelled['type'])) {
      $spelled['type'] = static::nullable($spelled['type'], $facts);
    }
    $schema = Schemas::defaults(static::described($facts), DefinitionSchema::keywordsOf(static::provisional($members), $spelled['type'] ?? NULL, $spelled));
    return static::finished($schema, $facts, $type);
  }

  /**
   * Writes the schema of a list of values from its facts and its item's.
   *
   * @param array $facts
   *   What the list's definition's array would hold.
   * @param array $item
   *   What its item's definition's array would hold.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its schema, as DefinitionSchema::of() reads it.
   */
  public static function list(array $facts, array $item): Schema {
    $members = static::members($facts, 'list');
    $list = [
      'type' => static::nullable('array', $facts),
      'items' => static::value($item),
    ];
    $schema = Schemas::defaults(static::described($facts), $list + DefinitionSchema::keywordsOf(static::provisional($members), 'array'));
    return static::finished($schema, $facts, 'list');
  }

  /**
   * Reads back the facts a value's or a list's schema was written from.
   *
   * What a definition of the schema would hold in its array, as
   * NodeDefinition::of() derives it: each fact from its member, else from
   * the keyword that holds it. A member that holds a schema (`shape`,
   * `configType`, `each`) is kept as it is held.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema of one value or a list, as value() or list() writes it.
   *
   * @return array
   *   The facts.
   */
  public static function factsOf(Schema $schema): array {
    $model = DefinitionSchema::model($schema);
    $facts = [];
    foreach (DefinitionSchema::PLACES as $key => [$member, $keyword]) {
      if ($member === 'type') {
        $facts[$key] = $model['type'] ?? NULL;
      }
      elseif (array_key_exists($member, $model)) {
        $facts[$key] = $model[$member];
      }
      elseif ($keyword !== NULL && $schema->has($keyword)) {
        $facts[$key] = $schema->get($keyword);
      }
    }
    foreach ((array) ($model['stored'] ?? []) as $key => $value) {
      $facts[$key] = $value;
    }
    return $facts;
  }

  /**
   * Answers whether a schema is one this class writes and reads back.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A key's schema.
   *
   * @return bool
   *   TRUE for one value or a list of one value, of a type LeafTypes
   *   derives the constraints of, held in neither `constraints` nor
   *   `definitionClass` (the classes value() and list() write).
   */
  public static function writes(Schema $schema): bool {
    if ($schema->isBoolean()) {
      return FALSE;
    }
    $model = DefinitionSchema::model($schema);
    if (!is_string($model['type'] ?? NULL) || isset($model['definitionClass']) || array_key_exists('constraints', $model) || $schema->has('properties')) {
      return FALSE;
    }
    if ($model['type'] !== 'list') {
      return isset(LeafTypes::DEFAULT_CONSTRAINTS[$model['type']]) && $model['type'] !== 'map';
    }
    $item = $schema->get('items');
    return $item instanceof Schema && static::writes($item) && DefinitionSchema::model($item)['type'] !== 'list';
  }

  /**
   * Writes a value's or a list's schema again, some facts changed.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema, one writes() answers TRUE for.
   * @param \Closure(array): array $change
   *   Takes the facts and gives them changed.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema written from the facts changed.
   */
  public static function with(Schema $schema, \Closure $change): Schema {
    assert(static::writes($schema));
    $facts = $change(static::factsOf($schema));
    $item = $schema->get('items');
    return DefinitionSchema::model($schema)['type'] === 'list' && $item instanceof Schema
      ? static::list($facts, static::factsOf($item))
      : static::value($facts);
  }

  /**
   * Writes what follows a value's keywords: its checks last, then MODEL.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   Its title, description and keywords.
   * @param array $facts
   *   Its facts.
   * @param string $type
   *   Its data type.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its schema.
   */
  private static function finished(Schema $schema, array $facts, string $type): Schema {
    $checks = array_intersect_key($schema->all(), array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_keys($checks) as $keyword) {
      $schema = $schema->without($keyword);
    }
    if (!empty($facts['secret'])) {
      $schema = $schema->with('writeOnly', TRUE);
    }
    else {
      $default = $facts['default_value'] ?? NULL;
      if ($default !== NULL) {
        $schema = $schema->with('default', $default);
      }
      $examples = is_array($facts['examples'] ?? NULL) ? $facts['examples'] : [];
      if ($examples !== []) {
        $schema = $schema->with('examples', array_values($examples));
      }
    }
    $schema = Schemas::defaults($schema, $checks);
    return $schema->with(DefinitionSchema::MODEL, static::annotation($facts, $type, $schema));
  }

  /**
   * Reads the title and description the facts give.
   *
   * @param array $facts
   *   The facts.
   *
   * @return \PortableSchema\Builder\Schema
   *   `title` and `description`, each where it is not NULL and not ''.
   */
  private static function described(array $facts): Schema {
    $described = Schema::any();
    $label = $facts['label'] ?? NULL;
    if ($label !== NULL && $label !== '') {
      $described = $described->with('title', $label);
    }
    $description = $facts['description'] ?? NULL;
    if ($description !== NULL && $description !== '') {
      $described = $described->with('description', $description);
    }
    return $described;
  }

  /**
   * Names the JSON type, and format, a value's facts say.
   *
   * As DefinitionSchema::type() names it for a DataDefinition.
   *
   * @param array $facts
   *   The facts.
   * @param string $type
   *   The data type.
   * @param array $members
   *   The MODEL members the facts give, the merged constraints derived
   *   from them.
   *
   * @return array
   *   `type`, and `format` where there is one; empty for none.
   */
  private static function spelled(array $facts, string $type, array $members): array {
    if ($type === 'string' && (($facts['settings'] ?? [])['widget'] ?? NULL) === 'date') {
      return ['type' => 'string', 'format' => 'date'];
    }
    if ($type === 'datetime_iso8601' && array_key_exists('DateOnly', NodeFacts::derived($members) ?? [])) {
      return LeafTypes::DATE_ONLY;
    }
    if (isset(LeafTypes::ROWS[$type])) {
      return LeafTypes::ROWS[$type];
    }
    return [];
  }

  /**
   * Allows null beside a type, as DefinitionSchema::nullable() does.
   *
   * @param string|array $type
   *   The JSON type.
   * @param array $facts
   *   The facts.
   *
   * @return string|array
   *   The type, or the type and null.
   */
  private static function nullable(string|array $type, array $facts): string|array {
    if (!empty($facts['required']) || !empty($facts['surface_always_held']) || !empty($facts['secret']) || is_array($type)) {
      return $type;
    }
    return [$type, 'null'];
  }

  /**
   * Makes a schema that holds only MODEL, for a reader of the members.
   *
   * @param array $members
   *   The members.
   *
   * @return \PortableSchema\Builder\Schema
   *   A schema NodeFacts::constraints() reads them from.
   */
  private static function provisional(array $members): Schema {
    return Schema::any()->with(DefinitionSchema::MODEL, $members);
  }

  /**
   * Places each fact as DefinitionSchema's MODEL members hold it.
   *
   * Every fact by its member, as stored; the ones a keyword holds as the
   * very value stored are left to the schema written (annotation()).
   *
   * @param array $facts
   *   The facts.
   * @param string $type
   *   The data type.
   *
   * @return array<string, mixed>
   *   The members, by name.
   */
  private static function members(array $facts, string $type): array {
    $members = ['type' => $type];
    $unnamed = [];
    foreach ($facts as $key => $value) {
      $place = DefinitionSchema::PLACES[$key] ?? NULL;
      if ($place === NULL || ($place[0] === 'type' && $value !== $type)) {
        $unnamed[$key] = $value;
        continue;
      }
      if ($place[0] !== 'type') {
        $members[$place[0]] = $value;
      }
    }
    if ($unnamed !== []) {
      $members['stored'] = $unnamed;
    }
    return $members;
  }

  /**
   * Writes MODEL, as DefinitionSchema::annotation() writes it.
   *
   * @param array $facts
   *   The facts.
   * @param string $type
   *   The data type.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema's keywords, MODEL aside.
   *
   * @return array<string, mixed>
   *   The members, in DefinitionSchema::MEMBERS's order.
   */
  private static function annotation(array $facts, string $type, Schema $schema): array {
    $members = ['type' => $type];
    $unnamed = [];
    foreach ($facts as $key => $value) {
      $place = DefinitionSchema::PLACES[$key] ?? NULL;
      if ($place === NULL || ($place[0] === 'type' && $value !== $type)) {
        $unnamed[$key] = $value;
        continue;
      }
      [$name, $keyword] = $place;
      if ($name === 'type' || ($keyword !== NULL && $schema->has($keyword) && $schema->get($keyword) === $value)) {
        continue;
      }
      $members[$name] = $value;
    }
    if ($unnamed !== []) {
      $members['stored'] = $unnamed;
    }
    return array_merge(array_intersect_key(array_fill_keys(DefinitionSchema::MEMBERS, NULL), $members), $members);
  }

}
