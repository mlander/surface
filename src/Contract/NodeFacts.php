<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * Reads the facts of a key the value pipeline needs from the key's node.
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
 * DefinitionSchema::isList(), nodeIsMap(), isLeaf(), declares() (the
 * DateOnly flag), propertyNode() and itemNode(); Secrets::marked() (a
 * secret).
 *
 * Every answer holds for the three classes a node holds every fact of
 * (DataDefinition, ListDataDefinition, MapDataDefinition). A node read from
 * another class (a configurable field) has no `required` or `settings`
 * member, so isRequired() and settings() answer as for none.
 *
 * Decision: see docs/decisions.md#the-value-pipeline-reads-nodes.
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
   *   TRUE where the `constraints` member holds one.
   */
  public static function hasConstraints(Schema $node): bool {
    return !empty(DefinitionSchema::model($node)['constraints']);
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

}
