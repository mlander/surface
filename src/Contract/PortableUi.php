<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

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
   * @param array|\PortableSchema\Builder\Schema $node
   *   The node: the emitter's `Schema`, or the schema reader's array.
   * @param array $ui
   *   The hints: members, and options merged into the node's own.
   *
   * @return array|\PortableSchema\Builder\Schema
   *   The node, in the form given.
   *
   * @phpstan-template T of array|\PortableSchema\Builder\Schema
   * @phpstan-param T $node
   * @phpstan-return T
   */
  public static function set(array|Schema $node, array $ui): array|Schema {
    if ($ui === []) {
      return $node;
    }
    $had = $node instanceof Schema ? $node->get(self::KEYWORD) : $node[self::KEYWORD] ?? NULL;
    $had = is_array($had) ? $had : [];
    if (is_array($had['options'] ?? NULL) && is_array($ui['options'] ?? NULL)) {
      $ui['options'] = $ui['options'] + $had['options'];
    }
    $merged = self::ordered($ui + $had);
    if ($node instanceof Schema) {
      return $node->has(self::KEYWORD) ? $node->with(self::KEYWORD, $merged) : PortableKeywords::before($node, [self::KEYWORD => $merged]);
    }
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The document's schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, settled.
   */
  public static function settled(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $node): Schema {
      if ($node->get(ContractEmitter::EXTENSION) === []) {
        $node = $node->without(ContractEmitter::EXTENSION);
      }
      $properties = $node->get('properties');
      if (!is_array($properties)) {
        return $node;
      }
      $groups = [];
      foreach ($properties as $name => $member) {
        $ui = $member instanceof Schema ? $member->get(self::KEYWORD) : NULL;
        $group = is_array($ui) ? ($ui['group'] ?? NULL) : NULL;
        if (is_array($group) && is_string($group['id'] ?? NULL)) {
          $id = $group['id'];
          unset($group['id']);
          $groups[$id] ??= $group;
          $ui['group'] = $id;
          $properties[$name] = $member->with(self::KEYWORD, $ui);
        }
      }
      return $groups === [] ? $node : self::set($node->with('properties', $properties), ['groups' => $groups]);
    });
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
