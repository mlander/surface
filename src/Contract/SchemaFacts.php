<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\Computed;
use Drupal\surface\Resolve;
use Drupal\surface\Resource\Arrangement;
use PortableSchema\Builder\Schema;

/**
 * Reads the facts of a key from the key's schema.
 *
 * For the value pipeline, the validator's own checks and the document's
 * classes, and for the readers still to move to schemas.
 *
 * Each method takes a built schema (DefinitionSchema::of(), an entry's
 * schema()) and answers what one call on the definition the schema was read
 * from answers; its docblock names that call. "The metadata getter" is
 * the module's static getter of what a definition stores, by that name;
 * the decisions entry's table names its class. The members of the model
 * annotation are reached through DefinitionSchema::model(), so the
 * pipeline names none of them. Static and pure: no service, no request,
 * nothing rendered, nothing kept.
 *
 * The other readers of a schema the pipeline uses stay where they are:
 * DefinitionSchema::isList(), isComplex(), holdsProperties(), isLeaf(),
 * declares() (the DateOnly flag), propertyOf() and itemOf();
 * Secrets::marked() (a secret).
 *
 * Every answer holds for the three classes a schema holds every fact of
 * (DataDefinition, ListDataDefinition, MapDataDefinition). A schema read from
 * another class (a configurable field) has no `required` or `settings`
 * member, so isRequired() and settings() answer as for none.
 *
 * Decision: see docs/decisions.md#the-value-pipeline-reads-schemas,
 * docs/decisions.md#the-validators-checks-and-the-document-read-schemas.
 *
 * @internal
 */
final class SchemaFacts {

  /**
   * Names the data type a schema was read with.
   *
   * Stands for getDataType().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string
   *   The `type` member; '' for a schema no definition was read into.
   */
  public static function dataType(Schema $schema): string {
    $type = DefinitionSchema::model($schema)['type'] ?? '';
    return is_string($type) ? $type : '';
  }

  /**
   * Answers whether a schema's definition declares any constraint.
   *
   * Stands for `getConstraints() !== []`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where constraints() gives one.
   */
  public static function hasConstraints(Schema $schema): bool {
    return static::constraints($schema) !== [];
  }

  /**
   * Answers whether a key is required, as its definition stores it.
   *
   * Stands for isRequired(). The key's own flag, not the parent's
   * `required` keyword, which leaves out a secret and a computed key.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `required` member is set.
   */
  public static function isRequired(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['required']);
  }

  /**
   * Answers whether a key's storage always holds a value for it.
   *
   * Stands for the metadata getter isAlwaysHeld().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `alwaysHeld` member is set.
   */
  public static function isAlwaysHeld(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['alwaysHeld']);
  }

  /**
   * Answers whether a key is the map a module's mounted keys sit in.
   *
   * Stands for the metadata getter isGrouping().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `grouping` member is set.
   */
  public static function isGrouping(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['grouping']);
  }

  /**
   * Answers whether a key holds a value whatever is sent.
   *
   * Stands for the metadata getter holdsValue(): required, or always held.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE when null cannot clear it.
   */
  public static function holdsValue(Schema $schema): bool {
    return static::isRequired($schema) || static::isAlwaysHeld($schema);
  }

  /**
   * Answers whether a key's schema also takes null.
   *
   * Stands for the metadata getters `!holdsValue() && !isSecret()`: an
   * optional key a caller may clear, never one that must hold a value,
   * nor a secret.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE when it does.
   */
  public static function isNullable(Schema $schema): bool {
    return !static::holdsValue($schema) && !Secrets::marked($schema);
  }

  /**
   * Gets a definition's settings.
   *
   * Stands for getSettings().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return array
   *   The `settings` member; empty where it holds none.
   */
  public static function settings(Schema $schema): array {
    $settings = DefinitionSchema::model($schema)['settings'] ?? [];
    return is_array($settings) ? $settings : [];
  }

  /**
   * Gets a definition's label, as stored.
   *
   * Stands for getLabel(). `title` holds a label that is neither NULL nor
   * '', and the `label` member the one that is, so a label of '' reads as
   * '' here, as getLabel() gives it: a caller that falls back on the key's
   * name for NULL alone keeps doing so. A label that is no text (a number
   * an array stores) is handed back as it is, as getLabel() hands it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return mixed
   *   The label as the definition holds it, a string or a text object
   *   in every definition the module makes; NULL where it holds none.
   */
  public static function label(Schema $schema): mixed {
    $title = $schema->isBoolean() ? NULL : $schema->get('title');
    return $title ?? DefinitionSchema::model($schema)['label'] ?? NULL;
  }

  /**
   * Gets the label a key's owner gave it.
   *
   * Stands for the metadata getter ownLabel(): the `ownLabel` member where
   * the definition stores one (NULL for a value that is no text), else
   * the label (label()).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|\Stringable|null
   *   The owner's label; the label it has, when none was kept.
   */
  public static function ownLabel(Schema $schema): string|\Stringable|null {
    $model = DefinitionSchema::model($schema);
    if (array_key_exists('ownLabel', $model)) {
      return is_string($model['ownLabel']) || $model['ownLabel'] instanceof \Stringable ? $model['ownLabel'] : NULL;
    }
    return static::label($schema);
  }

  /**
   * Answers whether a definition declares a default value of its own.
   *
   * Stands for the metadata getter hasDefaultValue(). An explicit NULL is
   * one.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `default` member or keyword is present.
   */
  public static function hasDefault(Schema $schema): bool {
    return array_key_exists('default', DefinitionSchema::model($schema)) || (!$schema->isBoolean() && $schema->has('default'));
  }

  /**
   * Gets the default value a definition declares of its own.
   *
   * Stands for the metadata getter getDefaultValue().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return mixed
   *   The `default` member where present, else the keyword; NULL for none.
   */
  public static function default(Schema $schema): mixed {
    $model = DefinitionSchema::model($schema);
    if (array_key_exists('default', $model)) {
      return $model['default'];
    }
    return $schema->isBoolean() ? NULL : $schema->get('default');
  }

  /**
   * Resolves a key's default, recursing through a map's properties.
   *
   * Stands for the metadata getter defaultOf(), rule for rule: a map's
   * default is assembled from its properties' (a property that declares
   * NULL is kept as NULL, one that declares nothing is left out), and the
   * map's own default merges over that assembly; a default that is not an
   * array stands on its own.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return mixed
   *   The default value, or NULL when none is declared.
   */
  public static function defaultOf(Schema $schema): mixed {
    $declared = static::hasDefault($schema);
    if (DefinitionSchema::isLeaf($schema) || DefinitionSchema::isList($schema)) {
      return $declared ? static::default($schema) : NULL;
    }
    $values = [];
    foreach (static::properties($schema) as $name => $property) {
      $nested = static::defaultOf($property);
      if ($nested !== NULL || static::hasDefault($property)) {
        $values[$name] = $nested;
      }
    }
    if (!$declared) {
      return $values === [] ? NULL : $values;
    }
    $own = static::default($schema);
    if (!is_array($own) || $values === []) {
      return $own;
    }
    return array_replace_recursive($values, $own);
  }

  /**
   * Gets the schemas of a map's properties.
   *
   * Stands for getPropertyDefinitions() of a map that has properties: a
   * built map's schema holds a schema for every property, a marked one
   * included, in the definition's order.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return array<string|int, \PortableSchema\Builder\Schema>
   *   The property schemas by name; empty for a schema that is no map (a list,
   *   one value, a map with no properties).
   */
  public static function properties(Schema $schema): array {
    $properties = $schema->isBoolean() ? NULL : $schema->get('properties');
    return is_array($properties) ? array_filter($properties, static fn (mixed $property): bool => $property instanceof Schema) : [];
  }

  /**
   * Gets a definition's description, as stored.
   *
   * Stands for getDescription(). `description` holds one that is neither
   * NULL nor '', and the `description` member the one that is, as label()
   * reads `title` and `label`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return mixed
   *   The description as the definition holds it; NULL where it holds none.
   */
  public static function description(Schema $schema): mixed {
    $description = $schema->isBoolean() ? NULL : $schema->get('description');
    return $description ?? DefinitionSchema::model($schema)['description'] ?? NULL;
  }

  /**
   * Gets every constraint a definition runs, its type's defaults included.
   *
   * Stands for getConstraints(). The `constraints` member where the schema
   * holds one, which the read writes only where derived() does not give
   * getConstraints() exactly; derived() where it holds none. The one
   * reader of the member.
   *
   * Decision: see docs/decisions.md#the-merged-constraint-list-is-derived.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return array
   *   Options by plugin id, as held, in getConstraints()'s order; empty
   *   where there are none, or for a schema no definition was read into.
   */
  public static function constraints(Schema $schema): array {
    $model = DefinitionSchema::model($schema);
    if (array_key_exists('constraints', $model)) {
      return is_array($model['constraints']) ? $model['constraints'] : [];
    }
    return static::derived($model) ?? [];
  }

  /**
   * Derives the merged constraint list from a schema's other members.
   *
   * What DataDefinition::getConstraints() gives, merged as core merges it:
   * the constraints the definition declares (`declared`), then each of
   * its type's defaults (LeafTypes::DEFAULT_CONSTRAINTS) and `NotNull`
   * where the key is required (`required`) whose id it does not declare,
   * so a declared one wins and keeps its place; where the key is required
   * and a `NotBlank` is among them, `NotBlank` allows NULL. No answer for
   * a type the table has no row for, or a definition with a `class` key,
   * whose class may give `AllowedValues`: the read writes the member for
   * those (DefinitionSchema::of()). Static and pure.
   *
   * @param array<string, mixed> $model
   *   A schema's MODEL members (DefinitionSchema::model()).
   *
   * @return array|null
   *   Options by plugin id; NULL where the members cannot say it.
   */
  public static function derived(array $model): ?array {
    $type = $model['type'] ?? NULL;
    $declared = $model['declared'] ?? [];
    if (!is_string($type) || !isset(LeafTypes::DEFAULT_CONSTRAINTS[$type]) || !is_array($declared) || array_key_exists('class', (array) ($model['stored'] ?? []))) {
      return NULL;
    }
    $required = !empty($model['required']);
    $defaults = LeafTypes::DEFAULT_CONSTRAINTS[$type];
    if ($required) {
      $defaults['NotNull'] = [];
    }
    $constraints = $declared + $defaults;
    if ($required && array_key_exists('NotBlank', $constraints)) {
      $constraints['NotBlank']['allowNull'] = TRUE;
    }
    return $constraints;
  }

  /**
   * Gets the constraints a definition declares of its own.
   *
   * Stands for `toArray()['constraints'] ?? []` of a DataDefinition: what
   * its array holds, without its type's defaults. A definition of another
   * class declares none here.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return array
   *   The `declared` member, as stored; empty where it holds none.
   */
  public static function declaredConstraints(Schema $schema): array {
    $declared = DefinitionSchema::model($schema)['declared'] ?? [];
    return is_array($declared) ? $declared : [];
  }

  /**
   * Answers whether a refinement ruled a key out.
   *
   * Stands for DoesNotApply::marks().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `doesNotApply` member is set.
   */
  public static function doesNotApply(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['doesNotApply']);
  }

  /**
   * Lists the ruled-out properties inside a map, by dotted path.
   *
   * The one walk of what a refinement ruled out below a key, for the
   * accept, the validator and the read tool's values: a key an alter
   * mounted is a property of the mount, refined one property at a time,
   * and a child's key a property of its shell, so each is ruled out
   * inside the map rather than at the top of the frame. A property ruled
   * out is listed and not walked into.
   *
   * It reads each schema's `properties` and its MODEL's mark alone. A schema
   * that holds `properties` was read from a complex definition and never
   * from a list's: DefinitionSchema::build() writes `properties` only in
   * its branch for a complex definition that is no list, and a list's
   * schema holds `items`. So a list's, a value's and a boolean schema give
   * none, as the walk on definitions it replaces gave none.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A key's schema, as refined.
   * @param string $path
   *   The path so far, with its trailing dot.
   *
   * @return string[]
   *   The dotted paths below the key, in the order of its properties;
   *   none for a schema that holds no `properties`.
   */
  public static function ruledOutInside(Schema $schema, string $path = ''): array {
    $properties = $schema->isBoolean() ? NULL : $schema->get('properties');
    $paths = [];
    foreach (is_array($properties) ? $properties : [] as $name => $property) {
      if (!$property instanceof Schema) {
        continue;
      }
      $name = $path . $name;
      if (static::doesNotApply($property)) {
        $paths[] = $name;
        continue;
      }
      array_push($paths, ...static::ruledOutInside($property, $name . '.'));
    }
    return $paths;
  }

  /**
   * Gets what a computed key is computed from.
   *
   * Stands for Computed::of(), the metadata getter computedOf().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \Drupal\surface\Computed|null
   *   The `compute` member, the object the definition holds; NULL for none.
   */
  public static function compute(Schema $schema): ?Computed {
    $computed = DefinitionSchema::model($schema)['compute'] ?? NULL;
    return $computed instanceof Computed ? $computed : NULL;
  }

  /**
   * Gets how a key's value is resolved from what is sent.
   *
   * Stands for the metadata getter resolveOf().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \Drupal\surface\Resolve|null
   *   The `resolve` member, the object the definition holds; NULL for none.
   */
  public static function resolve(Schema $schema): ?Resolve {
    $resolve = DefinitionSchema::model($schema)['resolve'] ?? NULL;
    return $resolve instanceof Resolve ? $resolve : NULL;
  }

  /**
   * Gets what every entry of a map of keys from the site is.
   *
   * Stands for the metadata getter eachOf(), its definition given as the
   * schema read from it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \PortableSchema\Builder\Schema|string|null
   *   The schema of the definition every entry has, or the class every entry
   *   is; NULL for none.
   */
  public static function each(Schema $schema): Schema|string|null {
    $each = DefinitionSchema::model($schema)['each'] ?? NULL;
    return $each instanceof Schema || is_string($each) ? $each : NULL;
  }

  /**
   * Names the key whose value chooses a key's variant.
   *
   * Stands for the metadata getter variantsBy().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|null
   *   The `variantsBy` member; NULL where it is no text.
   */
  public static function variantsBy(Schema $schema): ?string {
    $by = DefinitionSchema::model($schema)['variantsBy'] ?? NULL;
    return is_string($by) ? $by : NULL;
  }

  /**
   * Names where a key's variants are read from on the site.
   *
   * Stands for the metadata getter variantsFrom().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|null
   *   The `variantsFrom` member; NULL where it is no text.
   */
  public static function variantsFrom(Schema $schema): ?string {
    $from = DefinitionSchema::model($schema)['variantsFrom'] ?? NULL;
    return is_string($from) ? $from : NULL;
  }

  /**
   * Answers whether the site lists a key's variants.
   *
   * Stands for the metadata getter variantsListedBySite(): variants read
   * from the site, or the `readVariants` member set and not NULL.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where it does.
   */
  public static function readVariants(Schema $schema): bool {
    return static::variantsFrom($schema) !== NULL || isset(DefinitionSchema::model($schema)['readVariants']);
  }

  /**
   * Names the config schema type a key is declared with.
   *
   * Stands for the metadata getter configTypeOf().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|null
   *   The type of the `configType` member; NULL for none.
   */
  public static function configType(Schema $schema): ?string {
    $type = DefinitionSchema::model($schema)['configType']['type'] ?? NULL;
    return is_string($type) ? $type : NULL;
  }

  /**
   * Gets the schema of the definition a config schema type declares.
   *
   * Stands for the metadata getter configTypeDefinition(), given as the
   * schema read from it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema of the `configType` member; NULL for none.
   */
  public static function configTypeOf(Schema $schema): ?Schema {
    $type = DefinitionSchema::model($schema)['configType']['schema'] ?? NULL;
    return $type instanceof Schema ? $type : NULL;
  }

  /**
   * Names the shape a key is declared with.
   *
   * Stands for the metadata getter shapeOf(). Its schema is
   * DefinitionSchema::shapeOf().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|null
   *   The id of the `shape` member; NULL for none.
   */
  public static function shape(Schema $schema): ?string {
    $id = DefinitionSchema::model($schema)['shape']['id'] ?? NULL;
    return is_string($id) ? $id : NULL;
  }

  /**
   * Answers whether a key's context fixed it to one value.
   *
   * Stands for the metadata getter isFixed().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `fixed` member is set.
   */
  public static function isFixed(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['fixed']);
  }

  /**
   * Answers whether a deciding key's variants offer one value.
   *
   * Stands for the metadata getter isOneVariant().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `oneVariant` member is set.
   */
  public static function isOneVariant(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['oneVariant']);
  }

  /**
   * Answers whether an `any` is held whole.
   *
   * Stands for the metadata getter isHeldWhole(): the `heldWhole` member
   * set and not NULL.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where it is.
   */
  public static function isHeldWhole(Schema $schema): bool {
    return isset(DefinitionSchema::model($schema)['heldWhole']);
  }

  /**
   * Names the key a module's fieldset is drawn after.
   *
   * Stands for the metadata getter getPlacedAfter().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string|null
   *   The `placedAfter` member; NULL where it is no text.
   */
  public static function placedAfter(Schema $schema): ?string {
    $after = DefinitionSchema::model($schema)['placedAfter'] ?? NULL;
    return is_string($after) ? $after : NULL;
  }

  /**
   * Gets how a key is arranged where it is drawn.
   *
   * Stands for the metadata getter arrangementOf().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   The `arrangement` member, the object the definition holds; NULL for
   *   none.
   */
  public static function arrangement(Schema $schema): ?Arrangement {
    $arrangement = DefinitionSchema::model($schema)['arrangement'] ?? NULL;
    return $arrangement instanceof Arrangement ? $arrangement : NULL;
  }

  /**
   * Answers whether a value is read-only, as core's flag says.
   *
   * Stands for isReadOnly(): the `readOnly` member where it is set and not
   * NULL, else whether the value is computed (isComputed()).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where it is.
   */
  public static function isReadOnly(Schema $schema): bool {
    $model = DefinitionSchema::model($schema);
    return isset($model['readOnly']) ? !empty($model['readOnly']) : static::isComputed($schema);
  }

  /**
   * Answers whether a value is computed, as core's flag says.
   *
   * Stands for isComputed(); not the module's own computed key
   * (compute()).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where the `computed` member is set.
   */
  public static function isComputed(Schema $schema): bool {
    return !empty(DefinitionSchema::model($schema)['computed']);
  }

  /**
   * Gets one of a definition's settings.
   *
   * Stands for getSetting().
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   * @param string $name
   *   The setting's name.
   *
   * @return mixed
   *   The setting; NULL where it is not set.
   */
  public static function setting(Schema $schema, string $name): mixed {
    return static::settings($schema)[$name] ?? NULL;
  }

}
