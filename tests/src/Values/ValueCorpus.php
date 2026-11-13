<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Values;

use Drupal\surface\Pipeline\SurfacePipelineInterface;

/**
 * Yields the inputs a data surface's values are pinned with.
 *
 * It reads the surface's full document (ContractEmitter::emitFull()), so
 * it needs no service and no definition. Every place the document holds
 * is one place: through its defs, its branches, each variant and each
 * list's items. Given a base set of values, it yields one input per
 * change at a place. Each case is named "<pointer> <kind>" and carries the
 * stored values it is accepted over. The base case, "/ echoed", sends the
 * base values back over themselves. Every other case changes one thing.
 *
 * The kinds follow, one rule per kind of place. A later slice adds a kind
 * here, once, and never renames one, since the fixtures hold the names.
 *
 * Every leaf (a place that is not a map or a list) is sent:
 * - the stored value;
 * - the base values, over the stored value spelled as a string, where
 *   that value is a number or a boolean;
 * - its default;
 * - each option it offers (the `const` of each `oneOf` branch of the
 *   place or of its items), the option in upper case, the option inside
 *   spaces, and a number option as a string or a numeric string option
 *   as a number;
 * - each value of LEAF_VALUES;
 * - 1e20, a whole number beyond the integer range, where its document
 *   names the integer or the string type;
 * - a string of digits beyond the integer range, where its document
 *   names the integer type;
 * - nothing: the key omitted, and the key omitted where storage holds
 *   nothing for it either;
 * - an array, and a JSON string.
 * A leaf whose document says `format: date` or `format: date-time`, or
 * names the integer type, is also sent each string of DATE_VALUES. An
 * integer is in that rule because a timestamp is an integer in the
 * document.
 *
 * Every map and every list is sent: a JSON string of its own shape, a
 * JSON string of the other shape, a string that is no JSON, an empty
 * array, a number, NULL, the key omitted, and the key omitted where
 * storage holds nothing. A map is also sent an unknown key beside its
 * value, a list where the map is expected, and NULL where storage holds
 * a string. A list is also sent a bare item where the list is expected,
 * a null item last, a null item first, and its item under the keys 'x'
 * and 'y'. A list of maps is also sent an item that is not a map, and is
 * left out where storage holds an item that is not a map.
 *
 * A list whose items hold a secret is sent, for each such secret, over
 * one stored item that holds it: the item again without it, two items,
 * and three items. Over two stored items that hold it, it is sent three
 * items.
 *
 * Every secret (`writeOnly`) is sent the clear marker, '', NULL and
 * nothing, once over a stored secret and once over none. Its leaf kinds
 * are sent over none.
 *
 * Every key with variants (`x-surface.by` and `x-surface.variants`) is
 * sent each variant chosen with the key left out, and each variant
 * chosen with another variant's keys. For each variant that holds a
 * secret, it is sent: another variant chosen over that secret stored;
 * the same variant chosen with the secret left out; and this variant
 * chosen again over the secret stored aside while another was chosen.
 *
 * Every member of a variant is asked under its own variant. For each key
 * with variants, unless it lies below another key with variants and the
 * base holds nothing at its parent, and for each of its variants
 * the base does not choose, the base is changed: the deciding key holds
 * that variant, and the key holds the defaults the variant's members
 * declare. Every place of that variant is then asked a second time over
 * the changed base, named "<pointer> in variant <id>: <kind>". The
 * stored values stay the caller's. The variant the base chooses is asked
 * by the kinds above.
 *
 * A place asked a second time is sent the kinds of SECOND, each option
 * kind, each secret kind, each variant kind and each item secret kind,
 * and no other kind.
 *
 * Every locked (`readOnly`) or computed (`x-surface.computed`) key is
 * sent a value other than the base one.
 *
 * The whole surface is sent the base values (the base case), an empty
 * input, one unknown key at the top, one key one letter off a declared
 * one, and the base values over stored values that hold an unknown key.
 *
 * A base that is not the first of a data surface's value sets asks fewer
 * places. It is compared with the earlier value set it differs from at
 * the fewest places (nearest(), the first of those). It asks:
 * - each place where the two differ, a key one holds and the other does
 *   not included;
 * - each place below a deepest such place;
 * - each place whose `x-surface.dependsOn` names a place that differs;
 * - each place below a place whose `x-surface.dependsOn` names a place
 *   that differs, other than its own deciding key, asked a second time;
 *   below a key with variants, only the places of the variant the base
 *   chooses;
 * - the whole surface's cases.
 * A change of a deciding key alone asks no place below its key with
 * variants: the first value set asks every variant under that variant.
 * The kinds under a variant the base does not choose are asked for the
 * first value set alone. A value set exists to choose a variant or a
 * condition; every other place answers as it does under the first.
 *
 * A `dependsOn` name is read as a dotted path from the frame that holds
 * the key. A name that is a port of the frame (the `x-surface.reads` of
 * the key that attaches it) is the key the port reads, in the frame
 * around. A path no place of the document holds is read again from each
 * frame further out, and the first that a place holds is the one named.
 *
 * creates() yields the cases a write that creates the thing is recorded
 * with, over nothing stored: the base values, an empty input, an unknown
 * key, each place the base holds left out, and each computed key sent a
 * value.
 *
 * Deterministic: no randomness and no clock, the places in document
 * order and the kinds in the order above.
 */
final class ValueCorpus {

  /**
   * The name of the base case.
   */
  public const BASE = '/ echoed';

  /**
   * The value a stored secret holds in a case that stores one.
   */
  public const STORED_SECRET = 'stored-secret-value';

  /**
   * A key no document declares.
   */
  public const UNKNOWN = 'nothing_declares_this';

  /**
   * The datetime strings sent to a date place, by kind.
   *
   * The five input formats Coercion reads, with an offset; its storage
   * format, which has none, at three times of day; a date alone; a
   * numeric string; and a string that is no date.
   */
  public const DATE_VALUES = [
    'datetime RFC 3339' => '2026-03-04T05:06:07+02:00',
    'datetime ISO 8601' => '2026-03-04T05:06:07+0200',
    'datetime with microseconds' => '2026-03-04T05:06:07.123456+02:00',
    'datetime with a space' => '2026-03-04 05:06:07+02:00',
    'datetime with a space and no colon' => '2026-03-04 05:06:07+0200',
    'datetime as stored' => '2026-03-04T05:06:07',
    'datetime as stored at midnight' => '2026-03-04T00:00:00',
    'datetime as stored late in the day' => '2026-03-04T23:30:00',
    'date alone' => '2026-03-04',
    'date as a numeric string' => '1772600767',
    'not a date' => 'not a date',
  ];

  /**
   * The values sent to every leaf, by kind, after the options.
   */
  public const LEAF_VALUES = [
    "'7'" => '7',
    '7' => 7,
    '7.0' => 7.0,
    '7.5' => 7.5,
    "'7.5'" => '7.5',
    'TRUE' => TRUE,
    'FALSE' => FALSE,
    '0' => 0,
    '1' => 1,
    '0.0' => 0.0,
    '1.0' => 1.0,
    "'0'" => '0',
    "'1'" => '1',
    "'-0'" => '-0',
    "'true'" => 'true',
    "'false'" => 'false',
    "'yes'" => 'yes',
    "'no'" => 'no',
    "'on'" => 'on',
    "'off'" => 'off',
    "''" => '',
    'NULL' => NULL,
  ];

  /**
   * The kinds of LEAF_VALUES a place asked a second time is sent.
   */
  public const SECOND_LEAF = ["'7'", '7', 'TRUE', "'yes'", "''", 'NULL'];

  /**
   * The kinds of DATE_VALUES a place asked a second time is sent.
   */
  public const SECOND_DATES = ['datetime RFC 3339', 'datetime as stored', 'not a date'];

  /**
   * The kinds a place asked a second time is sent, besides some families.
   *
   * Each option kind, secret kind, variant kind and item secret kind is
   * sent too.
   */
  public const SECOND = [
    'stored',
    'default',
    ...self::SECOND_LEAF,
    ...self::SECOND_DATES,
    'omitted',
    'empty array',
    'NULL',
    'unknown key',
    'bare item',
    'null item first',
    'an item that is not a map',
    'locked sent a value',
    'computed sent a value',
  ];

  /**
   * Digits beyond the integer range, sent to an integer place.
   */
  public const BEYOND_DIGITS = '100000000000000000000';

  /**
   * A whole number beyond the integer range, for an integer or a string.
   */
  public const BEYOND_INTEGERS = 1e20;

  /**
   * Lists the places of a full document, in document order.
   *
   * @param array<string, mixed> $document
   *   The document's schema.
   *
   * @return array<string, array<string, mixed>>
   *   Each place by its pointer: its segments (an item is '0'), its kind
   *   ('leaf', 'map', 'list' or 'variants'), the JSON types the document
   *   names, its format, its default (one member when it has one), the
   *   options it offers, whether it is secret, locked or computed, the
   *   places its `x-surface.dependsOn` names, for a key with variants the
   *   deciding key and each variant's keys, secrets and own places, and
   *   for a list its item node and the secrets its item holds.
   */
  public static function places(array $document): array {
    $places = [];
    static::walk($document, $document, [], $places, 0, [], TRUE);
    $known = array_fill_keys(array_map('strval', array_keys($places)), TRUE);
    foreach ($places as &$place) {
      $place['depends'] = static::named($place['depends'], $known);
      foreach ($place['variants'] as &$variant) {
        foreach ($variant['places'] as &$own) {
          $own['depends'] = static::named($own['depends'], $known);
        }
        unset($own);
      }
      unset($variant);
    }
    unset($place);
    return $places;
  }

  /**
   * Yields the cases for a document and a base set of values.
   *
   * @param array<string, mixed> $document
   *   The data surface's full document.
   * @param array<string, mixed> $base
   *   The base values: sent back in the base case, and the stored values
   *   every case is accepted over unless its kind stores something else.
   * @param array<string, mixed>|null $first
   *   The earlier value set nearest the base (nearest()), when the base is
   *   not the first: only the places the base changes against it are
   *   asked. NULL asks every place.
   *
   * @return array<string, array{kind: string, input: array<string, mixed>, current: array<string, mixed>}>
   *   Each case by its name: its kind (the name less the pointer, with an
   *   option's or a variant's own name replaced by 'N'), its input and the
   *   stored values.
   */
  public static function cases(array $document, array $base, ?array $first = NULL): array {
    $cases = [];
    $add = static function (string $pointer, string $kind, array $input, array $current, ?string $family = NULL) use (&$cases): void {
      $cases[$pointer . ' ' . $kind] = [
        'kind' => $family ?? $kind,
        'input' => $input,
        'current' => $current,
      ];
    };
    $add('/', 'echoed', $base, $base);
    $add('/', 'empty input', [], $base);
    $add('/', 'unknown key', $base + [self::UNKNOWN => 'x'], $base);
    $misspelled = static::misspelled($document);
    if ($misspelled !== NULL) {
      $add('/', 'misspelled key', $base + [$misspelled => 'x'], $base);
    }
    $add('/', 'echoed over an unknown key stored', $base, $base + [self::UNKNOWN => 'x']);
    $places = static::places($document);
    $asked = $first === NULL ? NULL : static::asked($places, static::changed($first, $base), $base);
    foreach ($places as $pointer => $place) {
      if ($asked === NULL || isset($asked[(string) $pointer])) {
        static::ask($add, (string) $pointer, $place, $base, $base, '', '', $asked !== NULL && !$asked[(string) $pointer]);
      }
    }
    if ($first !== NULL) {
      return $cases;
    }
    foreach ($places as $pointer => $place) {
      if ($place['kind'] !== 'variants' || $place['by'] === NULL) {
        continue;
      }
      $at = $place['segments'];
      $by = [...array_slice($at, 0, -1), $place['by']];
      if (static::get($base, array_slice($at, 0, -1)) === [] && static::inVariant($places, $at)) {
        continue;
      }
      $decided = static::get($base, $by);
      foreach ($place['variants'] as $id => $variant) {
        if ($decided !== [] && is_scalar($decided[0]) && (string) $decided[0] === (string) $id) {
          continue;
        }
        $defaults = [];
        foreach ($variant['places'] as $own) {
          if (count($own['segments']) === count($at) + 1 && array_key_exists(0, $own['default'])) {
            $defaults[(string) end($own['segments'])] = $own['default'][0];
          }
        }
        $values = static::set(static::set($base, $by, (string) $id), $at, $defaults);
        foreach ($variant['places'] as $member => $own) {
          static::ask($add, (string) $member, $own, $values, $base, "in variant $id: ", 'in variant N: ', TRUE);
        }
      }
    }
    return $cases;
  }

  /**
   * Yields the cases a write that creates the thing is recorded with.
   *
   * Nothing is stored for any of them.
   *
   * @param array<string, mixed> $document
   *   The data surface's full document.
   * @param array<string, mixed> $base
   *   The base values.
   *
   * @return array<string, array{kind: string, input: array<string, mixed>, current: array<string, mixed>}>
   *   Each case by its name, as cases() names them.
   */
  public static function creates(array $document, array $base): array {
    $cases = [
      self::BASE => ['kind' => 'echoed', 'input' => $base, 'current' => []],
      '/ empty input' => ['kind' => 'empty input', 'input' => [], 'current' => []],
      '/ unknown key' => ['kind' => 'unknown key', 'input' => $base + [self::UNKNOWN => 'x'], 'current' => []],
    ];
    foreach (static::places($document) as $pointer => $place) {
      $at = $place['segments'];
      $held = static::get($base, $at);
      if ($held === []) {
        continue;
      }
      $cases[$pointer . ' omitted'] = ['kind' => 'omitted', 'input' => static::omit($base, $at), 'current' => []];
      if ($place['computed']) {
        $cases[$pointer . ' computed sent a value'] = [
          'kind' => 'computed sent a value',
          'input' => static::set($base, $at, static::other($held[0])),
          'current' => [],
        ];
      }
    }
    return $cases;
  }

  /**
   * Adds the cases of one place, over one input and one stored base.
   *
   * @param \Closure $add
   *   Adds a case: its pointer, its kind, its input, its stored values and
   *   its family.
   * @param string $pointer
   *   The place's pointer.
   * @param array<string, mixed> $place
   *   The place, as places() lists it.
   * @param array<string, mixed> $values
   *   The values each case changes one thing of.
   * @param array<string, mixed> $stored
   *   What storage holds, unless a kind stores something else.
   * @param string $prefix
   *   Put before each kind's name.
   * @param string $family
   *   Put before each kind's family.
   * @param bool $second
   *   TRUE for a place asked a second time: it is sent the second kinds
   *   alone (SECOND).
   */
  protected static function ask(\Closure $add, string $pointer, array $place, array $values, array $stored, string $prefix = '', string $family = '', bool $second = FALSE): void {
    $at = $place['segments'];
    $put = static function (string $kind, array $input, ?array $current = NULL, ?string $of = NULL) use ($add, $pointer, $stored, $prefix, $family, $second): void {
      if (!$second || in_array($of ?? $kind, self::SECOND, TRUE) || str_starts_with($of ?? $kind, 'option N') || str_starts_with($kind, 'secret ') || str_starts_with($of ?? $kind, 'variant N') || str_starts_with($of ?? $kind, 'item secret ')) {
        $add($pointer, $prefix . $kind, $input, $current ?? $stored, $family . ($of ?? $kind));
      }
    };
    $held = static::get($values, $at);
    if ($place['kind'] === 'leaf') {
      if (array_key_exists(0, $held)) {
        $put('stored', static::set($values, $at, $held[0]));
        if (is_int($held[0]) || is_float($held[0]) || is_bool($held[0])) {
          $spelled = is_bool($held[0]) ? ($held[0] ? '1' : '0') : (string) $held[0];
          $put('stored in another spelling', $values, static::set($stored, $at, $spelled));
        }
      }
      if (array_key_exists(0, $place['default'])) {
        $put('default', static::set($values, $at, $place['default'][0]));
      }
      foreach ($place['options'] as $n => $option) {
        $put("option $n", static::set($values, $at, $option), NULL, 'option N');
        if (is_string($option) && mb_strtoupper($option) !== $option) {
          $put("option $n upper", static::set($values, $at, mb_strtoupper($option)), NULL, 'option N upper');
        }
        if (is_string($option) || is_int($option) || is_float($option)) {
          $put("option $n spaced", static::set($values, $at, ' ' . $option . ' '), NULL, 'option N spaced');
        }
        if (is_int($option) || is_float($option)) {
          $put("option $n as a string", static::set($values, $at, (string) $option), NULL, 'option N as the other');
        }
        elseif (is_string($option) && is_numeric($option)) {
          $put("option $n as a number", static::set($values, $at, $option + 0), NULL, 'option N as the other');
        }
      }
      foreach (self::LEAF_VALUES as $kind => $value) {
        $put((string) $kind, static::set($values, $at, $value));
      }
      if (array_intersect(['integer', 'string'], $place['types']) !== []) {
        $put('1e20', static::set($values, $at, self::BEYOND_INTEGERS));
      }
      if (in_array('integer', $place['types'], TRUE)) {
        $put('digits beyond the integer range', static::set($values, $at, self::BEYOND_DIGITS));
      }
      $put('omitted', static::omit($values, $at));
      $put('omitted, not stored', static::omit($values, $at), static::omit($stored, $at));
      $put('array', static::set($values, $at, ['a']));
      $put('JSON string', static::set($values, $at, '{"a":1}'));
      if (in_array($place['format'], ['date', 'date-time'], TRUE) || in_array('integer', $place['types'], TRUE)) {
        foreach (self::DATE_VALUES as $kind => $value) {
          $put((string) $kind, static::set($values, $at, $value));
        }
      }
    }
    if (in_array($place['kind'], ['map', 'variants'], TRUE)) {
      $map = is_array($held[0] ?? NULL) && !array_is_list($held[0]) ? $held[0] : [];
      $put('JSON string', static::set($values, $at, json_encode($map === [] ? new \stdClass() : $map, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)));
      $put('JSON list', static::set($values, $at, '["a"]'));
      $put('broken JSON', static::set($values, $at, '{"a":'));
      $put('empty array', static::set($values, $at, []));
      $put('a number', static::set($values, $at, 7));
      $put('NULL', static::set($values, $at, NULL));
      $put('omitted', static::omit($values, $at));
      $put('omitted, not stored', static::omit($values, $at), static::omit($stored, $at));
      $put('unknown key', static::set($values, $at, $map + [self::UNKNOWN => 'x']));
      $put('list where a map is expected', static::set($values, $at, [$map]));
      $put('NULL over a stored string', static::set($values, $at, NULL), static::set($stored, $at, 'x'));
    }
    if ($place['kind'] === 'list') {
      $list = is_array($held[0] ?? NULL) && array_is_list($held[0]) ? $held[0] : [];
      $item = $list[0] ?? static::sample($place['item'] ?? []);
      $put('JSON string', static::set($values, $at, json_encode($list === [] ? [$item] : $list, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)));
      $put('JSON map', static::set($values, $at, '{"a":1}'));
      $put('broken JSON', static::set($values, $at, '["a",'));
      $put('empty array', static::set($values, $at, []));
      $put('a number', static::set($values, $at, 7));
      $put('NULL', static::set($values, $at, NULL));
      $put('omitted', static::omit($values, $at));
      $put('omitted, not stored', static::omit($values, $at), static::omit($stored, $at));
      $put('bare item', static::set($values, $at, $item));
      $put('null item', static::set($values, $at, [$item, NULL]));
      $put('null item first', static::set($values, $at, [NULL, $item]));
      $put('keyed items', static::set($values, $at, ['x' => $item, 'y' => $item]));
      if (is_array($item)) {
        $put('an item that is not a map', static::set($values, $at, [7]));
        $put('omitted, a stored item that is not a map', static::omit($values, $at), static::set($stored, $at, [7]));
      }
      foreach ($place['item secrets'] ?? [] as $secret) {
        $plain = is_array($item) ? array_diff_key($item, [$secret => TRUE]) : [];
        $one = static::set($stored, $at, [[$secret => self::STORED_SECRET] + $plain]);
        $put("item secret $secret stored, the item sent again", static::set($values, $at, [$plain]), $one, 'item secret stored, the item sent again');
        $put("item secret $secret stored, another item sent before it", static::set($values, $at, [$plain, $plain]), $one, 'item secret stored, another item sent before it');
        $put("item secret $secret stored, three items sent", static::set($values, $at, [$plain, $plain, $plain]), $one, 'item secret stored, three items sent');
        $kept = [$secret => self::STORED_SECRET] + $plain;
        $two = static::set($stored, $at, [$kept, $kept]);
        $put("item secret $secret stored in two items, three sent", static::set($values, $at, [$plain, $plain, $plain]), $two, 'item secret stored in two items, three sent');
      }
    }
    if ($place['secret']) {
      foreach (['stored' => static::set($stored, $at, self::STORED_SECRET), 'none' => static::set($stored, $at, NULL)] as $over => $current) {
        $put("secret clear marker over $over", static::set($values, $at, SurfacePipelineInterface::CLEAR_SECRET), $current);
        $put("secret '' over $over", static::set($values, $at, ''), $current);
        $put("secret NULL over $over", static::set($values, $at, NULL), $current);
        $put("secret omitted over $over", static::omit($values, $at), $current);
      }
    }
    if ($place['locked'] || $place['computed']) {
      $put($place['locked'] ? 'locked sent a value' : 'computed sent a value', static::set($values, $at, static::other($held[0] ?? NULL)));
    }
    if ($place['kind'] === 'variants' && $place['by'] !== NULL) {
      $by = [...array_slice($at, 0, -1), $place['by']];
      $ids = array_keys($place['variants']);
      foreach ($ids as $id) {
        $chosen = static::set($values, $by, (string) $id);
        $put("variant $id", static::omit($chosen, $at), NULL, 'variant N');
        $others = [];
        foreach ($place['variants'] as $other => $variant) {
          if ($other !== $id) {
            $others += array_fill_keys(array_diff($variant['keys'], $place['variants'][$id]['keys']), 'x');
          }
        }
        if ($others !== []) {
          $put("variant $id with another variant's keys", static::set($chosen, $at, $others), NULL, "variant N with another variant's keys");
        }
      }
      foreach ($place['variants'] as $id => $variant) {
        foreach ($variant['secrets'] as $secret) {
          $other = current(array_diff($ids, [$id]));
          if ($other === FALSE) {
            continue;
          }
          $kept = static::set(static::set($stored, $by, (string) $id), $at, [$secret => self::STORED_SECRET]);
          $put("variant $id secret $secret left for $other", static::omit(static::set($values, $by, (string) $other), $at), $kept, 'variant N secret left for another');
          $put("variant $id secret $secret kept", static::set(static::set($values, $by, (string) $id), $at, []), $kept, 'variant N secret kept');
          $aside = static::set(static::set($stored, $by, (string) $other), $at, [$secret => self::STORED_SECRET]);
          $put("variant $id secret $secret aside, chosen again", static::omit(static::set($values, $by, (string) $id), $at), $aside, 'variant N secret aside, chosen again');
        }
      }
    }
  }

  /**
   * Walks one node of a document into the places it holds.
   *
   * @param array<string, mixed> $document
   *   The document's schema, whose defs a `$ref` names.
   * @param mixed $node
   *   The node.
   * @param list<string> $segments
   *   Its place.
   * @param array<string, array<string, mixed>> $places
   *   The places so far; gains this one and those below it.
   * @param int $depth
   *   How deep the walk is.
   * @param array<string, array{0: list<string>, 1: list<string>}> $ports
   *   The ports of the frame that holds the node, by name: the frame the
   *   port reads from and the key it reads there.
   * @param bool $own
   *   TRUE to list each variant's own places as well; a walk of one
   *   variant's places lists no further variant's.
   */
  protected static function walk(array $document, mixed $node, array $segments, array &$places, int $depth, array $ports, bool $own): void {
    $node = static::resolved($document, $node);
    if ($node === NULL || $depth > 12) {
      return;
    }
    $pointer = static::pointer($segments);
    $branches = [];
    foreach (['anyOf', 'oneOf'] as $keyword) {
      foreach ((array) ($node[$keyword] ?? []) as $branch) {
        $branch = static::resolved($document, $branch);
        if ($branch !== NULL && !array_key_exists('const', $branch)) {
          $branches[] = $branch;
        }
      }
    }
    $types = (array) ($node['type'] ?? []);
    foreach ($branches as $branch) {
      $types = [...$types, ...(array) ($branch['type'] ?? [])];
    }
    $types = array_values(array_unique(array_map('strval', $types)));
    $surface = (array) ($node['x-surface'] ?? []);
    $variants = is_string($surface['by'] ?? NULL) && is_array($surface['variants'] ?? NULL) ? $surface['variants'] : NULL;
    $properties = (array) ($node['properties'] ?? []);
    foreach ($branches as $branch) {
      $properties += (array) ($branch['properties'] ?? []);
    }
    $items = is_array($node['items'] ?? NULL) ? $node['items'] : NULL;
    $kind = match (TRUE) {
      $variants !== NULL => 'variants',
      $items !== NULL || $types === ['array'] || $types === ['array', 'null'] => 'list',
      $properties !== [] || in_array('object', $types, TRUE) => 'map',
      default => 'leaf',
    };
    $options = [];
    foreach ((array) ($node['oneOf'] ?? []) as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        $options[] = $branch['const'];
      }
    }
    $place = $places[$pointer] ?? [
      'segments' => $segments,
      'kind' => $kind,
      'types' => $types,
      'format' => is_string($node['format'] ?? NULL) ? $node['format'] : NULL,
      'default' => array_key_exists('default', $node) ? [$node['default']] : [],
      'options' => $options,
      'secret' => FALSE,
      'locked' => FALSE,
      'computed' => FALSE,
      'by' => $variants === NULL ? NULL : $surface['by'],
      'variants' => [],
      'depends' => [],
    ];
    $frame = array_slice($segments, 0, -1);
    foreach ((array) ($surface['dependsOn'] ?? []) as $watched) {
      if (is_string($watched)) {
        $parts = explode('.', $watched);
        $port = $ports[$parts[0]] ?? NULL;
        $place['depends'][] = $port === NULL ? [$frame, $parts] : [$port[0], [...$port[1], ...array_slice($parts, 1)]];
      }
    }
    $place['depends'] = array_values(array_unique($place['depends'], SORT_REGULAR));
    if ($segments !== []) {
      $place['secret'] = $place['secret'] || ($node['writeOnly'] ?? FALSE) === TRUE;
      $place['locked'] = $place['locked'] || ($node['readOnly'] ?? FALSE) === TRUE;
      $place['computed'] = $place['computed'] || isset($surface['computed']);
      if ($kind === 'list' && $items !== NULL) {
        $place['item'] = static::resolved($document, $items) ?? [];
        $place['item secrets'] = [];
        foreach ((array) ($place['item']['properties'] ?? []) as $name => $property) {
          if ((static::resolved($document, $property)['writeOnly'] ?? FALSE) === TRUE) {
            $place['item secrets'][] = (string) $name;
          }
        }
      }
      $places[$pointer] = $place;
    }
    // The ports of the frame below: what the key that attaches it reads,
    // each from a key of the frame that holds that key.
    $below = [];
    foreach ((array) ($surface['reads'] ?? []) as $port => $read) {
      if (is_string($read)) {
        $below[(string) $port] = [$frame, explode('.', $read)];
      }
    }
    foreach ($properties as $name => $property) {
      static::walk($document, $property, [...$segments, (string) $name], $places, $depth + 1, $below, $own);
    }
    foreach ((array) ($node['allOf'] ?? []) as $rule) {
      foreach (['then', 'else'] as $side) {
        $flags = static::resolved($document, is_array($rule) ? $rule[$side] ?? NULL : NULL);
        foreach ((array) ($flags['properties'] ?? []) as $name => $property) {
          static::mark($document, $property, [...$segments, (string) $name], $places);
        }
      }
    }
    if ($items !== NULL) {
      static::walk($document, $items, [...$segments, '0'], $places, $depth + 1, $below, $own);
    }
    foreach ($variants ?? [] as $id => $reference) {
      $variant = static::resolved($document, ['$ref' => $reference]) ?? [];
      $keys = [];
      $secrets = [];
      $mine = [];
      foreach ((array) ($variant['properties'] ?? []) as $name => $property) {
        $keys[] = (string) $name;
        if ((static::resolved($document, $property)['writeOnly'] ?? FALSE) === TRUE) {
          $secrets[] = (string) $name;
        }
        static::walk($document, $property, [...$segments, (string) $name], $places, $depth + 1, $below, $own);
        if ($own) {
          static::walk($document, $property, [...$segments, (string) $name], $mine, $depth + 1, $below, FALSE);
        }
      }
      $places[$pointer]['variants'][(string) $id] = ['keys' => $keys, 'secrets' => $secrets, 'places' => $mine];
    }
  }

  /**
   * Marks a place a conditional branch locks or keeps secret.
   *
   * @param array<string, mixed> $document
   *   The document's schema.
   * @param mixed $node
   *   The branch's node for the place.
   * @param list<string> $segments
   *   The place.
   * @param array<string, array<string, mixed>> $places
   *   The places; the one at the place gains the flags.
   */
  protected static function mark(array $document, mixed $node, array $segments, array &$places): void {
    $node = static::resolved($document, $node);
    $pointer = static::pointer($segments);
    if ($node === NULL || !isset($places[$pointer])) {
      return;
    }
    $places[$pointer]['locked'] = $places[$pointer]['locked'] || ($node['readOnly'] ?? FALSE) === TRUE;
    $places[$pointer]['secret'] = $places[$pointer]['secret'] || ($node['writeOnly'] ?? FALSE) === TRUE;
    foreach ((array) ($node['properties'] ?? []) as $name => $property) {
      static::mark($document, $property, [...$segments, (string) $name], $places);
    }
  }

  /**
   * Reads the places a key's `dependsOn` names.
   *
   * @param list<array{0: list<string>, 1: list<string>}|string> $depends
   *   Each name as walk() read it: the frame it is read from and its
   *   segments there.
   * @param array<string, true> $known
   *   The pointer of every place of the document.
   *
   * @return list<string>
   *   The pointer of each place named: from the frame, or from the first
   *   frame further out where a place holds it; from the frame where none
   *   does.
   */
  protected static function named(array $depends, array $known): array {
    $named = [];
    foreach ($depends as $depend) {
      if (is_string($depend)) {
        $named[] = $depend;
        continue;
      }
      [$frame, $parts] = $depend;
      $found = static::pointer([...$frame, ...$parts]);
      $out = $frame;
      while (!isset($known[$found]) && $out !== []) {
        array_pop($out);
        if (isset($known[static::pointer([...$out, ...$parts])])) {
          $found = static::pointer([...$out, ...$parts]);
        }
      }
      $named[] = $found;
    }
    return array_values(array_unique($named));
  }

  /**
   * Finds the earlier value set a later one differs from at fewest places.
   *
   * @param array<string, mixed> $values
   *   The later value set.
   * @param list<array<string, mixed>> $earlier
   *   The value sets before it, in order.
   *
   * @return array<string, mixed>|null
   *   The first of the nearest; NULL when there is none before it.
   */
  public static function nearest(array $values, array $earlier): ?array {
    $nearest = NULL;
    $fewest = PHP_INT_MAX;
    foreach ($earlier as $other) {
      $count = count(static::changed($other, $values));
      if ($count < $fewest) {
        [$nearest, $fewest] = [$other, $count];
      }
    }
    return $nearest;
  }

  /**
   * Lists the places where one set of values differs from another.
   *
   * @param mixed $first
   *   The first values.
   * @param mixed $other
   *   The other values.
   * @param string $pointer
   *   The place of both.
   *
   * @return list<string>
   *   The pointer of each place below the top whose values differ, a map's
   *   or a list's as well as each member's.
   */
  protected static function changed(mixed $first, mixed $other, string $pointer = ''): array {
    if ($first === $other) {
      return [];
    }
    $changed = $pointer === '' ? [] : [$pointer];
    // Below a value one side holds as a map or a list and the other does
    // not, every member is changed, at every depth.
    $mine = is_array($first) ? $first : [];
    $theirs = is_array($other) ? $other : [];
    foreach (array_keys($mine + $theirs) as $key) {
      $below = $pointer . '/' . strtr((string) $key, ['~' => '~0', '/' => '~1']);
      if (array_key_exists($key, $mine) && array_key_exists($key, $theirs)) {
        $changed = [...$changed, ...static::changed($mine[$key], $theirs[$key], $below)];
        continue;
      }
      $held = array_key_exists($key, $mine) ? $mine[$key] : $theirs[$key];
      $changed = [...$changed, $below, ...(is_array($held) ? static::changed($held, NULL, $below) : [])];
    }
    return $changed;
  }

  /**
   * Lists the places a later value set asks.
   *
   * @param array<string, array<string, mixed>> $places
   *   The document's places.
   * @param list<string> $changed
   *   The places the value set changes.
   * @param array<string, mixed> $base
   *   The value set.
   *
   * @return array<string, bool>
   *   The pointer of each place asked: TRUE for one asked for what changed
   *   at it, FALSE for one asked a second time, below a place opened.
   */
  protected static function asked(array $places, array $changed, array $base): array {
    $asked = [];
    $opened = [];
    foreach ($places as $pointer => $place) {
      $pointer = (string) $pointer;
      if (static::reaches($pointer, $place['depends'], $changed)) {
        $asked[$pointer] = TRUE;
      }
      // A key with variants is opened by what it watches beside its
      // deciding key: each of its variants is asked under that variant
      // with the first value set already.
      $by = $place['by'] === NULL ? [] : [static::pointer([...array_slice($place['segments'], 0, -1), $place['by']])];
      if (array_intersect(array_diff($place['depends'], $by), $changed) !== []) {
        $opened[] = $pointer;
      }
    }
    foreach ($opened as $open) {
      $asked[$open] ??= FALSE;
      $place = $places[$open];
      if ($place['kind'] === 'variants' && $place['by'] !== NULL) {
        $decided = static::get($base, [...array_slice($place['segments'], 0, -1), $place['by']]);
        $id = $decided !== [] && is_scalar($decided[0]) ? (string) $decided[0] : NULL;
        foreach (array_keys($place['variants'][$id]['places'] ?? []) as $member) {
          $asked[(string) $member] ??= FALSE;
        }
        continue;
      }
      foreach (array_keys($places) as $pointer) {
        if (str_starts_with((string) $pointer, $open . '/')) {
          $asked[(string) $pointer] ??= FALSE;
        }
      }
    }
    return $asked;
  }

  /**
   * Answers whether a later value set asks a place for what changed at it.
   *
   * @param string $pointer
   *   The place.
   * @param list<string> $depends
   *   The places it depends on.
   * @param list<string> $changed
   *   The places the value set changes.
   *
   * @return bool
   *   TRUE when the place or a place it depends on is changed, or a place
   *   above it is changed and nothing between them is.
   */
  protected static function reaches(string $pointer, array $depends, array $changed): bool {
    foreach ($changed as $place) {
      if ($pointer === $place || in_array($place, $depends, TRUE)) {
        return TRUE;
      }
      if (str_starts_with($pointer, $place . '/') && !static::changesBelow($place, $changed)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a changed place has a changed place below it.
   *
   * @param string $place
   *   The place.
   * @param list<string> $changed
   *   The places a value set changes.
   *
   * @return bool
   *   TRUE when one is below it.
   */
  protected static function changesBelow(string $place, array $changed): bool {
    foreach ($changed as $other) {
      if (str_starts_with($other, $place . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a place lies below a key with variants.
   *
   * @param array<string, array<string, mixed>> $places
   *   The document's places.
   * @param list<string> $segments
   *   The place.
   *
   * @return bool
   *   TRUE when a place above it is a key with variants.
   */
  protected static function inVariant(array $places, array $segments): bool {
    for ($above = array_slice($segments, 0, -1); $above !== []; array_pop($above)) {
      if (($places[static::pointer($above)]['kind'] ?? NULL) === 'variants') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Names a key one letter off a declared one, which no document declares.
   *
   * @param array<string, mixed> $document
   *   The document's schema.
   *
   * @return string|null
   *   The first top-level key of four letters or more with an 'x' after
   *   it; NULL when there is none.
   */
  protected static function misspelled(array $document): ?string {
    $names = array_map('strval', array_keys((array) ($document['properties'] ?? [])));
    foreach ($names as $name) {
      if (strlen($name) >= 4 && !in_array($name . 'x', $names, TRUE)) {
        return $name . 'x';
      }
    }
    return NULL;
  }

  /**
   * Follows a node's `$ref` into the document's defs.
   *
   * @param array<string, mixed> $document
   *   The document's schema.
   * @param mixed $node
   *   The node.
   *
   * @return array<string, mixed>|null
   *   The node, its def's keywords beneath its own; NULL for no node.
   */
  protected static function resolved(array $document, mixed $node): ?array {
    for ($hops = 0; is_array($node) && is_string($node['$ref'] ?? NULL) && str_starts_with($node['$ref'], '#/$defs/') && $hops < 20; $hops++) {
      $def = $document['$defs'][substr($node['$ref'], 8)] ?? NULL;
      unset($node['$ref']);
      $node = is_array($def) ? $node + $def : $node;
    }
    return is_array($node) ? $node : NULL;
  }

  /**
   * Spells a place as a JSON pointer.
   *
   * @param list<string> $segments
   *   The place.
   *
   * @return string
   *   The pointer; '' for the top.
   */
  public static function pointer(array $segments): string {
    return implode('', array_map(static fn (string $segment): string => '/' . strtr($segment, ['~' => '~0', '/' => '~1']), $segments));
  }

  /**
   * Reads the value at a place.
   *
   * @param array<string, mixed> $values
   *   The values.
   * @param list<string> $segments
   *   The place.
   *
   * @return array{0?: mixed}
   *   One member, the value, when the values hold the place; empty when
   *   they do not.
   */
  protected static function get(array $values, array $segments): array {
    $value = $values;
    foreach ($segments as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return [];
      }
      $value = $value[$segment];
    }
    return [$value];
  }

  /**
   * Sets the value at a place, making each map or list above it.
   *
   * @param array<string, mixed> $values
   *   The values.
   * @param list<string> $segments
   *   The place, at least one segment.
   * @param mixed $value
   *   The value.
   *
   * @return array<string, mixed>
   *   The values, with the value at the place.
   */
  protected static function set(array $values, array $segments, mixed $value): array {
    $segment = array_shift($segments);
    if ($segments === []) {
      $values[$segment] = $value;
      return $values;
    }
    $inside = is_array($values[$segment] ?? NULL) ? $values[$segment] : [];
    $values[$segment] = static::set($inside, $segments, $value);
    return $values;
  }

  /**
   * Leaves the key at a place out.
   *
   * @param array<string, mixed> $values
   *   The values.
   * @param list<string> $segments
   *   The place, at least one segment.
   *
   * @return array<string, mixed>
   *   The values, without the key.
   */
  protected static function omit(array $values, array $segments): array {
    $segment = array_shift($segments);
    if ($segments === []) {
      unset($values[$segment]);
      return $values;
    }
    if (is_array($values[$segment] ?? NULL)) {
      $values[$segment] = static::omit($values[$segment], $segments);
    }
    return $values;
  }

  /**
   * Gives one item a list's item node describes.
   *
   * @param array<string, mixed> $node
   *   The item's node.
   *
   * @return mixed
   *   Its first option, else a value of its first type: 'a', 1, 1.5, TRUE,
   *   or a map holding its first property's value.
   */
  protected static function sample(array $node): mixed {
    foreach ((array) ($node['properties'] ?? []) as $name => $property) {
      return [(string) $name => static::sample(is_array($property) ? $property : [])];
    }
    foreach ((array) ($node['oneOf'] ?? []) as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        return $branch['const'];
      }
    }
    return match (((array) ($node['type'] ?? ['string']))[0] ?? 'string') {
      'integer' => 1,
      'number' => 1.5,
      'boolean' => TRUE,
      'object' => [],
      default => 'a',
    };
  }

  /**
   * Gives a value other than the one a locked or computed key holds.
   *
   * @param mixed $value
   *   What it holds.
   *
   * @return mixed
   *   A string with ' changed' after it, the integer after it, the other
   *   boolean, or 'changed'.
   */
  protected static function other(mixed $value): mixed {
    return match (TRUE) {
      is_string($value) => $value . ' changed',
      is_int($value) => $value + 1,
      is_bool($value) => !$value,
      default => 'changed',
    };
  }

}
