<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\Computed;
use Drupal\surface\Resolve;
use Drupal\surface\Resource\Arrangement;
use PortableSchema\Builder\Schema;

/**
 * Reads the facts of a key from the key's node.
 *
 * For the value pipeline, the validator's own checks and the document's
 * classes, and for the readers still to move to nodes.
 *
 * Each method takes a built node (DefinitionSchema::of(), an entry's
 * schema()) and answers what one call on the definition the node was read
 * from answers; its docblock names that call. "The metadata getter" is
 * the module's static getter of what a definition stores, by that name;
 * the decisions entry's table names its class. The members of the model
 * annotation are reached through DefinitionSchema::model(), so the
 * pipeline names none of them. Static and pure: no service, no request,
 * nothing rendered, nothing kept.
 *
 * The other readers of a node the pipeline uses stay where they are:
 * DefinitionSchema::isList(), isComplex(), nodeIsMap(), isLeaf(),
 * declares() (the DateOnly flag), propertyNode() and itemNode();
 * Secrets::marked() (a secret).
 *
 * Every answer holds for the three classes a node holds every fact of
 * (DataDefinition, ListDataDefinition, MapDataDefinition). A node read from
 * another class (a configurable field) has no `required` or `settings`
 * member, so isRequired() and settings() answer as for none.
 *
 * Decision: see docs/decisions.md#the-value-pipeline-reads-nodes,
 * docs/decisions.md#the-validators-checks-and-the-document-read-nodes.
 *
 * @internal
 */
final class NodeFacts {

  /**
   * Names the data type a node was read with.
   *
   * Stands for getDataType().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string
   *   The `type` member; '' for a node no definition was read into.
   */
  public static function dataType(Schema $node): string {
    $type = DefinitionSchema::model($node)['type'] ?? '';
    return is_string($type) ? $type : '';
  }

  /**
   * Answers whether a node's definition declares any constraint.
   *
   * Stands for `getConstraints() !== []`.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where constraints() gives one.
   */
  public static function hasConstraints(Schema $node): bool {
    return static::constraints($node) !== [];
  }

  /**
   * Answers whether a key is required, as its definition stores it.
   *
   * Stands for isRequired(). The key's own flag, not the parent's
   * `required` keyword, which leaves out a secret and a computed key.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `required` member is set.
   */
  public static function isRequired(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['required']);
  }

  /**
   * Answers whether a key's storage always holds a value for it.
   *
   * Stands for the metadata getter isAlwaysHeld().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `alwaysHeld` member is set.
   */
  public static function isAlwaysHeld(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['alwaysHeld']);
  }

  /**
   * Answers whether a key is the map a module's mounted keys sit in.
   *
   * Stands for the metadata getter isGrouping().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `grouping` member is set.
   */
  public static function isGrouping(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['grouping']);
  }

  /**
   * Answers whether a key holds a value whatever is sent.
   *
   * Stands for the metadata getter holdsValue(): required, or always held.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE when null cannot clear it.
   */
  public static function holdsValue(Schema $node): bool {
    return static::isRequired($node) || static::isAlwaysHeld($node);
  }

  /**
   * Answers whether a key's schema also takes null.
   *
   * Stands for the metadata getters `!holdsValue() && !isSecret()`: an
   * optional key a caller may clear, never one that must hold a value,
   * nor a secret.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE when it does.
   */
  public static function isNullable(Schema $node): bool {
    return !static::holdsValue($node) && !Secrets::marked($node);
  }

  /**
   * Gets a definition's settings.
   *
   * Stands for getSettings().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return array
   *   The `settings` member; empty where it holds none.
   */
  public static function settings(Schema $node): array {
    $settings = DefinitionSchema::model($node)['settings'] ?? [];
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
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return mixed
   *   The label as the definition holds it, a string or a text object
   *   in every definition the module makes; NULL where it holds none.
   */
  public static function label(Schema $node): mixed {
    $title = $node->isBoolean() ? NULL : $node->get('title');
    return $title ?? DefinitionSchema::model($node)['label'] ?? NULL;
  }

  /**
   * Gets the label a key's owner gave it.
   *
   * Stands for the metadata getter ownLabel(): the `ownLabel` member where
   * the definition stores one (NULL for a value that is no text), else
   * the label (label()).
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|\Stringable|null
   *   The owner's label; the label it has, when none was kept.
   */
  public static function ownLabel(Schema $node): string|\Stringable|null {
    $model = DefinitionSchema::model($node);
    if (array_key_exists('ownLabel', $model)) {
      return is_string($model['ownLabel']) || $model['ownLabel'] instanceof \Stringable ? $model['ownLabel'] : NULL;
    }
    return static::label($node);
  }

  /**
   * Answers whether a definition declares a default value of its own.
   *
   * Stands for the metadata getter hasDefaultValue(). An explicit NULL is
   * one.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `default` member or keyword is present.
   */
  public static function hasDefault(Schema $node): bool {
    return array_key_exists('default', DefinitionSchema::model($node)) || (!$node->isBoolean() && $node->has('default'));
  }

  /**
   * Gets the default value a definition declares of its own.
   *
   * Stands for the metadata getter getDefaultValue().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return mixed
   *   The `default` member where present, else the keyword; NULL for none.
   */
  public static function default(Schema $node): mixed {
    $model = DefinitionSchema::model($node);
    if (array_key_exists('default', $model)) {
      return $model['default'];
    }
    return $node->isBoolean() ? NULL : $node->get('default');
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
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return mixed
   *   The default value, or NULL when none is declared.
   */
  public static function defaultOf(Schema $node): mixed {
    $declared = static::hasDefault($node);
    if (DefinitionSchema::isLeaf($node) || DefinitionSchema::isList($node)) {
      return $declared ? static::default($node) : NULL;
    }
    $values = [];
    foreach (static::properties($node) as $name => $property) {
      $nested = static::defaultOf($property);
      if ($nested !== NULL || static::hasDefault($property)) {
        $values[$name] = $nested;
      }
    }
    if (!$declared) {
      return $values === [] ? NULL : $values;
    }
    $own = static::default($node);
    if (!is_array($own) || $values === []) {
      return $own;
    }
    return array_replace_recursive($values, $own);
  }

  /**
   * Gets the nodes of a map's properties.
   *
   * Stands for getPropertyDefinitions() of a map that has properties: a
   * built map's node holds a node for every property, a marked one
   * included, in the definition's order.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return array<string|int, \PortableSchema\Builder\Schema>
   *   The property nodes by name; empty for a node that is no map (a list,
   *   one value, a map with no properties).
   */
  public static function properties(Schema $node): array {
    $properties = $node->isBoolean() ? NULL : $node->get('properties');
    return is_array($properties) ? array_filter($properties, static fn (mixed $property): bool => $property instanceof Schema) : [];
  }

  /**
   * Gets a definition's description, as stored.
   *
   * Stands for getDescription(). `description` holds one that is neither
   * NULL nor '', and the `description` member the one that is, as label()
   * reads `title` and `label`.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return mixed
   *   The description as the definition holds it; NULL where it holds none.
   */
  public static function description(Schema $node): mixed {
    $description = $node->isBoolean() ? NULL : $node->get('description');
    return $description ?? DefinitionSchema::model($node)['description'] ?? NULL;
  }

  /**
   * Gets every constraint a definition runs, its type's defaults included.
   *
   * Stands for getConstraints(). The `constraints` member where the node
   * holds one, which the read writes only where derived() does not give
   * getConstraints() exactly; derived() where it holds none. The one
   * reader of the member.
   *
   * Decision: see docs/decisions.md#the-merged-constraint-list-is-derived.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return array
   *   Options by plugin id, as held, in getConstraints()'s order; empty
   *   where there are none, or for a node no definition was read into.
   */
  public static function constraints(Schema $node): array {
    $model = DefinitionSchema::model($node);
    if (array_key_exists('constraints', $model)) {
      return is_array($model['constraints']) ? $model['constraints'] : [];
    }
    return static::derived($model) ?? [];
  }

  /**
   * Derives the merged constraint list from a node's other members.
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
   *   A node's MODEL members (DefinitionSchema::model()).
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
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return array
   *   The `declared` member, as stored; empty where it holds none.
   */
  public static function declaredConstraints(Schema $node): array {
    $declared = DefinitionSchema::model($node)['declared'] ?? [];
    return is_array($declared) ? $declared : [];
  }

  /**
   * Answers whether a refinement ruled a key out.
   *
   * Stands for DoesNotApply::marks().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `doesNotApply` member is set.
   */
  public static function doesNotApply(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['doesNotApply']);
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
   * It reads each node's `properties` and its MODEL's mark alone. A node
   * that holds `properties` was read from a complex definition and never
   * from a list's: DefinitionSchema::build() writes `properties` only in
   * its branch for a complex definition that is no list, and a list's
   * node holds `items`. So a list's, a value's and a boolean node give
   * none, as the walk on definitions it replaces gave none.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A key's node, as refined.
   * @param string $path
   *   The path so far, with its trailing dot.
   *
   * @return string[]
   *   The dotted paths below the key, in the order of its properties;
   *   none for a node that holds no `properties`.
   */
  public static function ruledOutInside(Schema $node, string $path = ''): array {
    $properties = $node->isBoolean() ? NULL : $node->get('properties');
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
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return \Drupal\surface\Computed|null
   *   The `compute` member, the object the definition holds; NULL for none.
   */
  public static function compute(Schema $node): ?Computed {
    $computed = DefinitionSchema::model($node)['compute'] ?? NULL;
    return $computed instanceof Computed ? $computed : NULL;
  }

  /**
   * Gets how a key's value is resolved from what is sent.
   *
   * Stands for the metadata getter resolveOf().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return \Drupal\surface\Resolve|null
   *   The `resolve` member, the object the definition holds; NULL for none.
   */
  public static function resolve(Schema $node): ?Resolve {
    $resolve = DefinitionSchema::model($node)['resolve'] ?? NULL;
    return $resolve instanceof Resolve ? $resolve : NULL;
  }

  /**
   * Gets what every entry of a map of keys from the site is.
   *
   * Stands for the metadata getter eachOf(), its definition given as the
   * node read from it.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return \PortableSchema\Builder\Schema|string|null
   *   The node of the definition every entry has, or the class every entry
   *   is; NULL for none.
   */
  public static function each(Schema $node): Schema|string|null {
    $each = DefinitionSchema::model($node)['each'] ?? NULL;
    return $each instanceof Schema || is_string($each) ? $each : NULL;
  }

  /**
   * Names the key whose value chooses a key's variant.
   *
   * Stands for the metadata getter variantsBy().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|null
   *   The `variantsBy` member; NULL where it is no text.
   */
  public static function variantsBy(Schema $node): ?string {
    $by = DefinitionSchema::model($node)['variantsBy'] ?? NULL;
    return is_string($by) ? $by : NULL;
  }

  /**
   * Names where a key's variants are read from on the site.
   *
   * Stands for the metadata getter variantsFrom().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|null
   *   The `variantsFrom` member; NULL where it is no text.
   */
  public static function variantsFrom(Schema $node): ?string {
    $from = DefinitionSchema::model($node)['variantsFrom'] ?? NULL;
    return is_string($from) ? $from : NULL;
  }

  /**
   * Answers whether the site lists a key's variants.
   *
   * Stands for the metadata getter variantsListedBySite(): variants read
   * from the site, or the `readVariants` member set and not NULL.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where it does.
   */
  public static function readVariants(Schema $node): bool {
    return static::variantsFrom($node) !== NULL || isset(DefinitionSchema::model($node)['readVariants']);
  }

  /**
   * Names the config schema type a key is declared with.
   *
   * Stands for the metadata getter configTypeOf().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|null
   *   The type of the `configType` member; NULL for none.
   */
  public static function configType(Schema $node): ?string {
    $type = DefinitionSchema::model($node)['configType']['type'] ?? NULL;
    return is_string($type) ? $type : NULL;
  }

  /**
   * Gets the node of the definition a config schema type declares.
   *
   * Stands for the metadata getter configTypeDefinition(), given as the
   * node read from it.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The node of the `configType` member; NULL for none.
   */
  public static function configTypeNode(Schema $node): ?Schema {
    $type = DefinitionSchema::model($node)['configType']['node'] ?? NULL;
    return $type instanceof Schema ? $type : NULL;
  }

  /**
   * Names the shape a key is declared with.
   *
   * Stands for the metadata getter shapeOf(). Its node is
   * DefinitionSchema::shapeNode().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|null
   *   The id of the `shape` member; NULL for none.
   */
  public static function shape(Schema $node): ?string {
    $id = DefinitionSchema::model($node)['shape']['id'] ?? NULL;
    return is_string($id) ? $id : NULL;
  }

  /**
   * Answers whether a key's context fixed it to one value.
   *
   * Stands for the metadata getter isFixed().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `fixed` member is set.
   */
  public static function isFixed(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['fixed']);
  }

  /**
   * Answers whether a deciding key's variants offer one value.
   *
   * Stands for the metadata getter isOneVariant().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `oneVariant` member is set.
   */
  public static function isOneVariant(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['oneVariant']);
  }

  /**
   * Answers whether an `any` is held whole.
   *
   * Stands for the metadata getter isHeldWhole(): the `heldWhole` member
   * set and not NULL.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where it is.
   */
  public static function isHeldWhole(Schema $node): bool {
    return isset(DefinitionSchema::model($node)['heldWhole']);
  }

  /**
   * Names the key a module's fieldset is drawn after.
   *
   * Stands for the metadata getter getPlacedAfter().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return string|null
   *   The `placedAfter` member; NULL where it is no text.
   */
  public static function placedAfter(Schema $node): ?string {
    $after = DefinitionSchema::model($node)['placedAfter'] ?? NULL;
    return is_string($after) ? $after : NULL;
  }

  /**
   * Gets how a key is arranged where it is drawn.
   *
   * Stands for the metadata getter arrangementOf().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   The `arrangement` member, the object the definition holds; NULL for
   *   none.
   */
  public static function arrangement(Schema $node): ?Arrangement {
    $arrangement = DefinitionSchema::model($node)['arrangement'] ?? NULL;
    return $arrangement instanceof Arrangement ? $arrangement : NULL;
  }

  /**
   * Answers whether a value is read-only, as core's flag says.
   *
   * Stands for isReadOnly(): the `readOnly` member where it is set and not
   * NULL, else whether the value is computed (isComputed()).
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where it is.
   */
  public static function isReadOnly(Schema $node): bool {
    $model = DefinitionSchema::model($node);
    return isset($model['readOnly']) ? !empty($model['readOnly']) : static::isComputed($node);
  }

  /**
   * Answers whether a value is computed, as core's flag says.
   *
   * Stands for isComputed(); not the module's own computed key
   * (compute()).
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   *
   * @return bool
   *   TRUE where the `computed` member is set.
   */
  public static function isComputed(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['computed']);
  }

  /**
   * Gets one of a definition's settings.
   *
   * Stands for getSetting().
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node.
   * @param string $name
   *   The setting's name.
   *
   * @return mixed
   *   The setting; NULL where it is not set.
   */
  public static function setting(Schema $node, string $name): mixed {
    return static::settings($node)[$name] ?? NULL;
  }

}
