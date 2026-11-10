<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\Contract\Keywords\BoundsRow;
use Drupal\surface\Contract\Keywords\CompositeRow;
use Drupal\surface\Contract\Keywords\ConstraintRowInterface;
use Drupal\surface\Contract\Keywords\EmailRow;
use Drupal\surface\Contract\Keywords\NotBlankRow;
use Drupal\surface\Contract\Keywords\ReadBack;
use Drupal\surface\Contract\Keywords\RegexRow;
use Drupal\surface\Contract\Keywords\UniqueItemsRow;
use Drupal\surface\Contract\Keywords\UuidRow;
use Drupal\surface\Contract\Keywords\Written;

/**
 * The constraints the contract states in keywords, and the keywords back.
 *
 * One row per constraint (Keywords\ConstraintRowInterface), read in both
 * directions: the emitter writes a definition's constraints through
 * state(), the config schema document writes a config schema's through
 * it too, and the schema reader reads a value's keywords back through
 * read(), so a constraint and its keywords are said in one place.
 *
 * | Constraint | Applies to | Written | Read back as |
 * | --- | --- | --- | --- |
 * | Range | integer, number | `minimum`, `maximum` | Range |
 * | Length | string | `minLength`, `maxLength` | Length |
 * | Count | array | `minItems`, `maxItems` | Count |
 * | UniqueItems | array | `uniqueItems: true`, last | UniqueItems |
 * | NotBlank | string | `minLength: 1` | Length, `min: 1` |
 * | Email | string | `format: email` | the `email` type |
 * | Regex | string | `pattern`, `not: {pattern}` | Regex |
 * | Uuid | string | `format: uuid` | Uuid |
 * | Sequentially | its parts' | `allOf` of its parts | Sequentially |
 * | AtLeastOneOf | its parts' | `anyOf` of its parts | AtLeastOneOf |
 *
 * `exactly` on Length and Count is written as both bounds; an exclusive
 * bound on an integer reads back as Range. Each row's docblock says the
 * rest.
 *
 * Nothing is dropped silently: a constraint with a row that does not
 * apply to the value's type, or whose options its keywords cannot state,
 * is left to the caller, which names it under `portable-check`;
 * the reader keeps a row's keywords it cannot read in the JsonSchema
 * constraint. A constraint with no row is the caller's too: a list of
 * values is the options resolvers' `oneOf`, and the rest are checked on
 * the server.
 *
 * Closed for now: a module cannot add a row. A row is a class behind one
 * interface so the table can open (a service tag, or an interface on the
 * constraint class) without reshaping it.
 *
 * Decision: see
 * docs/decisions.md#one-table-maps-a-constraint-to-its-keywords-both-ways.
 *
 * @internal
 */
final class ConstraintKeywords {

  /**
   * How a keyword two rows write on one property is merged, by keyword.
   *
   * A lower bound by the larger, an upper bound by the smaller, so
   * NotBlank and Length give the same `minLength` in either order; an
   * `allOf` by its parts, in order. Any other keyword two rows write
   * differently leaves the constraint whose row stands later in the table
   * unstated (accepted()).
   */
  public const MERGE = [
    'minimum' => 'max',
    'minLength' => 'max',
    'minItems' => 'max',
    'maximum' => 'min',
    'maxLength' => 'min',
    'maxItems' => 'min',
    'allOf' => 'append',
  ];

  /**
   * The keywords written after every other, where the reader reads them.
   */
  public const LAST = ['uniqueItems'];

  /**
   * The order the reader declares what it reads, by constraint.
   *
   * A constraint not here is declared where its first keyword stands; the
   * bounds after them, where their first keyword stands; UniqueItems
   * last.
   */
  public const READ_ORDER = [
    'Range' => 1,
    'Length' => 1,
    'Count' => 1,
    'UniqueItems' => 2,
  ];

  /**
   * The rows, by constraint plugin id.
   *
   * @var array<string, \Drupal\surface\Contract\Keywords\ConstraintRowInterface>|null
   */
  private static ?array $rows = NULL;

  /**
   * Gets the rows.
   *
   * @return array<string, \Drupal\surface\Contract\Keywords\ConstraintRowInterface>
   *   Each row, by the constraint plugin id it maps.
   */
  public static function rows(): array {
    if (self::$rows === NULL) {
      self::$rows = [];
      $rows = [
        new BoundsRow('Range', 'minimum', 'maximum', ['integer', 'number']),
        new BoundsRow('Length', 'minLength', 'maxLength', ['string']),
        new BoundsRow('Count', 'minItems', 'maxItems', ['array']),
        new UniqueItemsRow(),
        new NotBlankRow(),
        new EmailRow(),
        new RegexRow(),
        new UuidRow(),
        new CompositeRow('Sequentially', 'allOf'),
        new CompositeRow('AtLeastOneOf', 'anyOf'),
      ];
      foreach ($rows as $row) {
        foreach ($row->constraints() as $id) {
          self::$rows[$id] = $row;
        }
      }
    }
    return self::$rows;
  }

  /**
   * Names the constraints a row maps.
   *
   * @return string[]
   *   The constraint plugin ids.
   */
  public static function ids(): array {
    return array_keys(self::rows());
  }

  /**
   * Gets the row for a constraint.
   *
   * @param string $id
   *   The constraint plugin id.
   *
   * @return \Drupal\surface\Contract\Keywords\ConstraintRowInterface|null
   *   The row, or NULL for a constraint no row maps.
   */
  public static function row(string $id): ?ConstraintRowInterface {
    return self::rows()[$id] ?? NULL;
  }

  /**
   * Writes one constraint as keywords.
   *
   * @param string $id
   *   The constraint plugin id.
   * @param mixed $options
   *   Its options.
   * @param string[] $types
   *   The value's JSON types; null is left out.
   *
   * @return \Drupal\surface\Contract\Keywords\Written|null
   *   The keywords; NULL when no row maps the constraint, its row does not
   *   apply to the types, or its options cannot be stated.
   */
  public static function write(string $id, mixed $options, array $types): ?Written {
    $row = self::row($id);
    $types = self::types($types);
    if ($row === NULL || !$row->appliesTo($types)) {
      return NULL;
    }
    return $row->write($id, is_array($options) ? $options : [], $types);
  }

  /**
   * States a value's constraints as keywords, merged by the table's rule.
   *
   * @param iterable $constraints
   *   The constraints, options by plugin id.
   * @param string[] $types
   *   The value's JSON types; null is left out.
   * @param array $keywords
   *   What the value says already (its `type` and `format`), which the
   *   constraints' keywords are merged into.
   *
   * @return array{keywords: array, extras: array, left: list<array{0: string, 1: mixed, 2: bool}>}
   *   The keywords, those given first; what goes beside them (a Regex's
   *   message by code, under `messages`); and each constraint the
   *   keywords do not state whole, in order, with its options and whether
   *   a row maps it. A row's constraint left here is checked on the
   *   server; one with no row is the caller's to decide.
   */
  public static function state(iterable $constraints, array $types, array $keywords = []): array {
    $extras = [];
    $left = [];
    $constraints = is_array($constraints) ? $constraints : iterator_to_array($constraints);
    $accepted = self::accepted($constraints, $types, $keywords);
    foreach ($constraints as $id => $options) {
      $id = (string) $id;
      if (self::row($id) === NULL) {
        $left[] = [$id, $options, FALSE];
        continue;
      }
      $written = self::write($id, $options, $types);
      $merged = $written === NULL || !in_array($id, $accepted, TRUE) ? NULL : self::merge($keywords, $written->keywords);
      if ($written === NULL || $merged === NULL) {
        $left[] = [$id, $options, TRUE];
        continue;
      }
      $keywords = $merged;
      foreach ($written->extras as $extra => $value) {
        // A map (the messages by code) gains what it does not hold yet.
        $extras[$extra] = is_array($value) && is_array($extras[$extra] ?? NULL) ? $extras[$extra] + $value : $extras[$extra] ?? $value;
      }
      if (!$written->whole || $written->keywords === []) {
        $left[] = [$id, $options, TRUE];
      }
    }
    foreach (self::LAST as $keyword) {
      if (array_key_exists($keyword, $keywords)) {
        $value = $keywords[$keyword];
        unset($keywords[$keyword]);
        $keywords[$keyword] = $value;
      }
    }
    return ['keywords' => $keywords, 'extras' => $extras, 'left' => $left];
  }

  /**
   * Names the constraints whose keywords a value states, in the table's order.
   *
   * Two rows that write one keyword differently, where no rule merges it
   * (`format` of Email and of Uuid), cannot both be stated: the row that
   * stands first in the table is, and the other is left, whatever order
   * the constraints were added in. What the value says already (its
   * `type`, its `format`) comes before any row.
   *
   * @param array $constraints
   *   The constraints, options by plugin id.
   * @param string[] $types
   *   The value's JSON types; null is left out.
   * @param array $keywords
   *   What the value says already.
   *
   * @return string[]
   *   The ids of the constraints whose keywords are written.
   */
  public static function accepted(array $constraints, array $types, array $keywords = []): array {
    $order = array_flip(self::ids());
    $ids = array_values(array_filter(array_map('strval', array_keys($constraints)), static fn (string $id): bool => isset($order[$id])));
    usort($ids, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
    $accepted = [];
    foreach ($ids as $id) {
      $written = self::write($id, $constraints[$id], $types);
      $merged = $written === NULL ? NULL : self::merge($keywords, $written->keywords);
      if ($merged !== NULL) {
        $keywords = $merged;
        $accepted[] = $id;
      }
    }
    return $accepted;
  }

  /**
   * Merges one constraint's keywords into a value's.
   *
   * @param array $keywords
   *   The value's keywords so far.
   * @param array $written
   *   The constraint's.
   *
   * @return array|null
   *   The keywords merged, or NULL when one is said already otherwise and
   *   no rule merges it.
   */
  public static function merge(array $keywords, array $written): ?array {
    foreach ($written as $keyword => $value) {
      if (!array_key_exists($keyword, $keywords) || $keywords[$keyword] === $value) {
        $keywords[$keyword] = $value;
        continue;
      }
      $keywords[$keyword] = match (self::MERGE[$keyword] ?? NULL) {
        'max' => max($keywords[$keyword], $value),
        'min' => min($keywords[$keyword], $value),
        'append' => [...$keywords[$keyword], ...$value],
        default => NULL,
      };
      if ($keywords[$keyword] === NULL) {
        return NULL;
      }
    }
    return $keywords;
  }

  /**
   * Reads the constraints a value's keywords state.
   *
   * @param array $schema
   *   The value's schema.
   * @param string[] $types
   *   The value's JSON types; null is left out.
   *
   * @return \Drupal\surface\Contract\Keywords\ReadBack[]
   *   What each row read, in the order the reader declares it
   *   (READ_ORDER): a row that does not apply keeps the keywords of its
   *   own the schema holds.
   */
  public static function read(array $schema, array $types): array {
    $types = self::types($types);
    $read = [];
    foreach (self::rows() as $id => $row) {
      if ($row->constraints()[0] !== $id) {
        continue;
      }
      if ($row->appliesTo($types)) {
        $back = $row->read($schema, $types);
      }
      else {
        $kept = array_intersect_key($schema, array_flip($row->keywords()));
        $back = $kept === [] ? NULL : new ReadBack(NULL, kept: $kept, note: 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it of a value of this type.');
      }
      if ($back !== NULL) {
        $read[] = $back;
      }
    }
    return self::ordered($read, $schema);
  }

  /**
   * Orders what was read as the reader declares it.
   *
   * The reader orders what it reads itself (a list of values, a kept
   * schema) among the rows' by the same rule, as constraints not in
   * READ_ORDER.
   *
   * @param \Drupal\surface\Contract\Keywords\ReadBack[] $read
   *   What was read.
   * @param array $schema
   *   The schema it was read from.
   *
   * @return \Drupal\surface\Contract\Keywords\ReadBack[]
   *   The same, by READ_ORDER, then by where the first keyword each took
   *   or kept stands.
   */
  public static function ordered(array $read, array $schema): array {
    $at = array_flip(array_map('strval', array_keys($schema)));
    $place = static function (ReadBack $back) use ($at): array {
      $keywords = [...$back->consumed, ...array_map('strval', array_keys($back->kept))];
      $positions = array_map(static fn (string $keyword): int => $at[$keyword] ?? PHP_INT_MAX, $keywords);
      return [self::READ_ORDER[$back->constraint ?? ''] ?? 0, $positions === [] ? PHP_INT_MAX : min($positions)];
    };
    usort($read, static fn (ReadBack $a, ReadBack $b): int => $place($a) <=> $place($b));
    return $read;
  }

  /**
   * Answers whether a row reads a format as a constraint.
   *
   * @param string $format
   *   The format.
   *
   * @return bool
   *   TRUE for `uuid`.
   */
  public static function readsFormat(string $format): bool {
    foreach (self::read(['format' => $format], ['string']) as $back) {
      if (in_array('format', $back->consumed, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Leaves null out of a value's JSON types.
   *
   * @param array $types
   *   The types.
   *
   * @return string[]
   *   The types but null.
   */
  protected static function types(array $types): array {
    return array_values(array_diff($types, ['null']));
  }

}
