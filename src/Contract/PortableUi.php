<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

/**
 * Writes the widget hints as the dialect's `x-portable-ui`.
 *
 * How a key is drawn, never what it accepts. On a key's schema, its
 * members in this order:
 *
 * - `widget`: the widget word (SurfaceWidgetManager's selection), left out
 *   where no widget claims the key;
 * - `options`: `multiple` on a list of allowed values drawn as one select,
 *   the key's `placeholder`, `prefix`, `suffix` and `help` where set, and
 *   `emptyOption` (`{show, label}`) on a select or radios of one value;
 * - `group`: the id of the group the key is drawn in;
 * - `weight`: its weight among its frame's keys;
 * - `groups`: on the object schema whose `properties` the keys are (the
 *   root, a structure's or a module mount's def, a map), each group a key
 *   names, declared once: `{label, description?, widget, options?,
 *   weight?}`, `widget` `fieldset` or `details`, `options` `{"open":
 *   false}` for a group that starts collapsed.
 *
 * While a document is written, a key's `group` holds the whole group
 * (declaration()), so a site and a def compare it as any part of the
 * keyword; settled() declares each group on its frame at the end.
 *
 * Decision: see docs/decisions.md#the-ui-hints-are-x-portable-ui.
 *
 * @internal
 */
final class PortableUi {

  /**
   * The keyword.
   */
  public const KEYWORD = PortableKeywords::UI;

  /**
   * The members, in the order a node carries them.
   */
  public const MEMBERS = ['widget', 'options', 'group', 'weight', 'groups'];

  /**
   * The options Surface writes, in the order a node carries them.
   */
  public const OPTIONS = ['multiple', 'placeholder', 'prefix', 'suffix', 'help', 'emptyOption'];

  /**
   * The members and options that are widget hints, written when asked for.
   *
   * Everything but `options.emptyOption`, which the schema for these
   * answers carries whatever is asked.
   */
  public const HINTS = ['widget', 'multiple', 'placeholder', 'prefix', 'suffix', 'help', 'group', 'weight', 'groups'];

  /**
   * The keywords whose value is a map of subschemas.
   */
  protected const MAPS = ['properties', '$defs', 'patternProperties', 'dependentSchemas'];

  /**
   * The keywords whose value is a list of subschemas.
   */
  protected const LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

  /**
   * The keywords whose value is one subschema.
   */
  protected const SINGLE = [
    'items',
    'additionalProperties',
    'unevaluatedProperties',
    'unevaluatedItems',
    'contains',
    'propertyNames',
    'not',
    'if',
    'then',
    'else',
  ];

  /**
   * Writes a key's hints, each member only where it says something.
   *
   * @param string|null $widget
   *   The widget word, or NULL when no widget claims the key.
   * @param array $options
   *   The widget's options, by name (OPTIONS).
   * @param array|null $group
   *   The group the key is drawn in, as declaration() writes it.
   * @param int|null $weight
   *   Its weight among its frame's keys.
   *
   * @return array
   *   The keyword's value; empty where it says nothing.
   */
  public static function write(?string $widget, array $options = [], ?array $group = NULL, ?int $weight = NULL): array {
    $ui = [];
    if ($widget !== NULL) {
      $ui['widget'] = $widget;
    }
    if ($options !== []) {
      $ui['options'] = $options;
    }
    if ($group !== NULL) {
      $ui['group'] = $group;
    }
    if ($weight !== NULL) {
      $ui['weight'] = $weight;
    }
    return self::ordered($ui);
  }

  /**
   * Writes a group as its frame declares it, with its id.
   *
   * @param string $id
   *   The group's id.
   * @param string $label
   *   Its label, plain text.
   * @param string $as
   *   `fieldset` or `details` (ArrangementGroup).
   * @param bool $collapsed
   *   Whether it starts collapsed.
   * @param int|null $weight
   *   Its weight among its frame's keys and groups.
   * @param string|null $description
   *   Its description, plain text.
   *
   * @return array
   *   `{id, label, description?, widget, options?, weight?}`.
   */
  public static function declaration(string $id, string $label, string $as, bool $collapsed = FALSE, ?int $weight = NULL, ?string $description = NULL): array {
    $group = ['id' => $id, 'label' => $label];
    if ($description !== NULL && $description !== '') {
      $group['description'] = $description;
    }
    $group['widget'] = $as;
    if ($collapsed) {
      $group['options'] = ['open' => FALSE];
    }
    if ($weight !== NULL) {
      $group['weight'] = $weight;
    }
    return $group;
  }

  /**
   * Adds hints to a node's `x-portable-ui`, placed before its `x-surface`.
   *
   * @param array $node
   *   The node.
   * @param array $ui
   *   The hints: members, and options merged into the node's own.
   *
   * @return array
   *   The node.
   */
  public static function set(array $node, array $ui): array {
    if ($ui === []) {
      return $node;
    }
    $had = is_array($node[self::KEYWORD] ?? NULL) ? $node[self::KEYWORD] : [];
    if (is_array($had['options'] ?? NULL) && is_array($ui['options'] ?? NULL)) {
      $ui['options'] = $ui['options'] + $had['options'];
    }
    $merged = self::ordered($ui + $had);
    if (array_key_exists(self::KEYWORD, $node)) {
      $node[self::KEYWORD] = $merged;
      return $node;
    }
    return PortableKeywords::before($node, [self::KEYWORD => $merged]);
  }

  /**
   * Strips members and options from a node's hints.
   *
   * @param array $ui
   *   The keyword's value.
   * @param string[] $names
   *   The members and options to strip: the two share no name.
   *
   * @return array
   *   What is left, with no `options` where none is; empty for nothing.
   */
  public static function without(array $ui, array $names): array {
    if ($names === []) {
      return $ui;
    }
    $ui = array_diff_key($ui, array_flip(array_diff($names, ['options'])));
    if (is_array($ui['options'] ?? NULL)) {
      $ui['options'] = array_diff_key($ui['options'], array_flip($names));
      if ($ui['options'] === []) {
        unset($ui['options']);
      }
    }
    return $ui;
  }

  /**
   * Declares each group on its frame, at every depth.
   *
   * Each key whose `group` holds the whole group (declaration()) names it
   * by id; the object schema whose `properties` the key is declares it
   * once under `groups`, in the order its keys first name them, after its
   * own members. An `x-surface` that said nothing else than what moved
   * here (a key's widget) is left out.
   *
   * @param array $schema
   *   The document's schema.
   *
   * @return array
   *   The same, settled.
   */
  public static function settled(array $schema): array {
    // A subschema that said nothing but an `x-surface` left empty is the
    // empty schema, an object: an empty JSON array is no schema.
    $inside = static fn (array $member): array|\stdClass => ($settled = self::settled($member)) === [] && $member !== [] ? new \stdClass() : $settled;
    foreach ($schema as $keyword => $value) {
      if (!is_array($value)) {
        continue;
      }
      if (in_array($keyword, self::MAPS, TRUE)) {
        foreach ($value as $name => $member) {
          if (is_array($member)) {
            $schema[$keyword][$name] = $inside($member);
          }
        }
      }
      elseif (in_array($keyword, self::LISTS, TRUE) && array_is_list($value)) {
        foreach ($value as $i => $member) {
          if (is_array($member)) {
            $schema[$keyword][$i] = $inside($member);
          }
        }
      }
      elseif (in_array($keyword, self::SINGLE, TRUE)) {
        $schema[$keyword] = $inside($value);
      }
    }
    if (($schema[ContractEmitter::EXTENSION] ?? NULL) === []) {
      unset($schema[ContractEmitter::EXTENSION]);
    }
    if (!is_array($schema['properties'] ?? NULL)) {
      return $schema;
    }
    $groups = [];
    foreach ($schema['properties'] as $name => $member) {
      $group = is_array($member) ? ($member[self::KEYWORD]['group'] ?? NULL) : NULL;
      if (is_array($group) && is_string($group['id'] ?? NULL)) {
        $id = $group['id'];
        unset($group['id']);
        $groups[$id] ??= $group;
        $schema['properties'][$name][self::KEYWORD]['group'] = $id;
      }
    }
    return $groups === [] ? $schema : self::set($schema, ['groups' => $groups]);
  }

  /**
   * Orders a keyword's members, and its options, as a node carries them.
   *
   * @param array $ui
   *   The keyword's value.
   *
   * @return array
   *   The same, ordered.
   */
  protected static function ordered(array $ui): array {
    if (is_array($ui['options'] ?? NULL)) {
      $ui['options'] = array_merge(array_intersect_key(array_flip(self::OPTIONS), $ui['options']), $ui['options']);
    }
    return array_merge(array_intersect_key(array_flip(self::MEMBERS), $ui), $ui);
  }

}
