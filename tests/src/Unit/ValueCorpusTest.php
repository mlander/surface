<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\Tests\surface\Values\ValueCorpus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cases the value corpus yields for a document, kind by kind.
 *
 * @see \Drupal\Tests\surface\Values\ValueCorpus
 */
#[Group('surface')]
class ValueCorpusTest extends UnitTestCase {

  /**
   * The kinds every leaf is sent, after its stored value, default and options.
   */
  protected const LEAF = [
    "'7'",
    '7',
    '7.0',
    '7.5',
    "'7.5'",
    'TRUE',
    'FALSE',
    '0',
    '1',
    '0.0',
    '1.0',
    "'0'",
    "'1'",
    "'-0'",
    "'true'",
    "'false'",
    "'yes'",
    "'no'",
    "'on'",
    "'off'",
    "''",
    'NULL',
  ];

  /**
   * The kinds every leaf is sent after LEAF.
   */
  protected const NOTHING = [
    'omitted',
    'omitted, not stored',
    'array',
    'JSON string',
  ];

  /**
   * The kinds a date or an integer place is sent besides.
   */
  protected const DATES = [
    'datetime RFC 3339',
    'datetime ISO 8601',
    'datetime with microseconds',
    'datetime with a space',
    'datetime with a space and no colon',
    'datetime as stored',
    'datetime as stored at midnight',
    'datetime as stored late in the day',
    'date alone',
    'date as a numeric string',
    'not a date',
  ];

  /**
   * A document holding one place of each kind.
   *
   * @return array<string, mixed>
   *   The document's schema.
   */
  protected static function document(): array {
    return [
      'type' => 'object',
      'properties' => [
        'size' => ['type' => 'string', 'oneOf' => [['const' => 'small'], ['const' => 'large']], 'default' => 'small'],
        'count' => ['type' => ['integer', 'null']],
        'when' => ['type' => 'string', 'format' => 'date-time'],
        'settings' => [
          'type' => 'object',
          'properties' => ['color' => ['type' => 'string', 'x-surface' => ['dependsOn' => ['size']]]],
        ],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'flag' => ['type' => 'boolean'],
        'people' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'pin' => ['type' => 'string', 'writeOnly' => TRUE]],
          ],
        ],
        'extra' => [
          'type' => 'object',
          'properties' => ['a' => ['type' => 'string']],
          'x-surface' => ['dependsOn' => ['size']],
        ],
        'lead' => ['$ref' => '#/$defs/person', 'x-surface' => ['reads' => ['listed' => 'kind']]],
        'token' => ['type' => 'string', 'writeOnly' => TRUE],
        'fixed' => ['type' => 'string', 'readOnly' => TRUE, 'x-surface' => ['dependsOn' => ['size']]],
        'slug' => ['type' => 'string', 'x-surface' => ['computed' => ['from' => ['size']]]],
        'kind' => ['type' => 'string', 'oneOf' => [['const' => 'a'], ['const' => 'b']]],
        'credential' => [
          'anyOf' => [['$ref' => '#/$defs/a'], ['$ref' => '#/$defs/b'], ['type' => 'null']],
          'x-surface' => ['by' => 'kind', 'variants' => ['a' => '#/$defs/a', 'b' => '#/$defs/b']],
        ],
      ],
      '$defs' => [
        'a' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string', 'writeOnly' => TRUE]]],
        'b' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'default' => 'n']]],
        'person' => [
          'type' => 'object',
          'properties' => ['email' => ['type' => 'string', 'x-surface' => ['dependsOn' => ['listed']]]],
        ],
      ],
    ];
  }

  /**
   * The base values the cases change.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected static function base(): array {
    return [
      'size' => 'small',
      'count' => 3,
      'when' => NULL,
      'settings' => ['color' => 'red'],
      'tags' => ['x'],
      'flag' => TRUE,
      'people' => [['name' => 'p']],
      'extra' => ['a' => 'e'],
      'lead' => ['email' => NULL],
      'token' => NULL,
      'fixed' => 'f',
      'slug' => 's',
      'kind' => 'a',
      'credential' => ['key' => NULL],
    ];
  }

  /**
   * Tests every place is found, with what the document says of it.
   */
  public function testPlaces(): void {
    $places = ValueCorpus::places(static::document());
    $said = array_map(static fn (array $place): array => [
      $place['kind'],
      $place['secret'] ? 'secret' : ($place['locked'] ? 'locked' : ($place['computed'] ? 'computed' : '')),
    ], $places);
    $this->assertSame([
      '/size' => ['leaf', ''],
      '/count' => ['leaf', ''],
      '/when' => ['leaf', ''],
      '/settings' => ['map', ''],
      '/settings/color' => ['leaf', ''],
      '/tags' => ['list', ''],
      '/tags/0' => ['leaf', ''],
      '/flag' => ['leaf', ''],
      '/people' => ['list', ''],
      '/people/0' => ['map', ''],
      '/people/0/name' => ['leaf', ''],
      '/people/0/pin' => ['leaf', 'secret'],
      '/extra' => ['map', ''],
      '/extra/a' => ['leaf', ''],
      '/lead' => ['map', ''],
      '/lead/email' => ['leaf', ''],
      '/token' => ['leaf', 'secret'],
      '/fixed' => ['leaf', 'locked'],
      '/slug' => ['leaf', 'computed'],
      '/kind' => ['leaf', ''],
      '/credential' => ['variants', ''],
      '/credential/key' => ['leaf', 'secret'],
      '/credential/name' => ['leaf', ''],
    ], $said);
    $this->assertSame(['small', 'large'], $places['/size']['options']);
    $this->assertSame(['small'], $places['/size']['default']);
    $this->assertSame(['integer', 'null'], $places['/count']['types']);
    $this->assertSame('date-time', $places['/when']['format']);
    $this->assertSame(['pin'], $places['/people']['item secrets']);
    $this->assertSame('kind', $places['/credential']['by']);
    $variants = array_map(
      static fn (array $variant): array => [$variant['keys'], $variant['secrets'], array_keys($variant['places'])],
      $places['/credential']['variants'],
    );
    $this->assertSame([
      'a' => [['key'], ['key'], ['/credential/key']],
      'b' => [['name'], [], ['/credential/name']],
    ], $variants);
    $this->assertSame(['n'], $places['/credential']['variants']['b']['places']['/credential/name']['default'], "A variant's own places hold what that variant declares.");

    // A dependsOn name: a sibling, a name read again from the frame
    // further out, and a port read from the key the attaching key names.
    $this->assertSame(['/size'], $places['/fixed']['depends']);
    $this->assertSame(['/size'], $places['/settings/color']['depends'], 'No place is /settings/size, so the name is read from the frame further out.');
    $this->assertSame(['/kind'], $places['/lead/email']['depends'], 'The port reads kind, in the frame around the key that attaches the frame.');
  }

  /**
   * Tests the names of the cases, place by place, in order.
   */
  public function testNames(): void {
    $names = array_keys(ValueCorpus::cases(static::document(), static::base()));
    $by = [];
    foreach ($names as $name) {
      [$pointer, $kind] = explode(' ', $name, 2);
      $by[$pointer][] = $kind;
    }
    $options = ['option 0', 'option 0 upper', 'option 0 spaced', 'option 1', 'option 1 upper', 'option 1 spaced'];
    $leaf = [...self::LEAF, '1e20', ...self::NOTHING];
    $secret = [];
    foreach (['stored', 'none'] as $over) {
      foreach (['clear marker', "''", 'NULL', 'omitted'] as $sent) {
        $secret[] = "secret $sent over $over";
      }
    }
    $map = [
      'JSON string',
      'JSON list',
      'broken JSON',
      'empty array',
      'a number',
      'NULL',
      'omitted',
      'omitted, not stored',
      'unknown key',
      'list where a map is expected',
      'NULL over a stored string',
    ];
    $list = [
      'JSON string',
      'JSON map',
      'broken JSON',
      'empty array',
      'a number',
      'NULL',
      'omitted',
      'omitted, not stored',
      'bare item',
      'null item',
      'null item first',
      'keyed items',
    ];
    $this->assertSame([
      '/' => ['echoed', 'empty input', 'unknown key', 'misspelled key', 'echoed over an unknown key stored'],
      '/size' => ['stored', 'default', ...$options, ...$leaf],
      '/count' => [
        'stored',
        'stored in another spelling',
        ...self::LEAF,
        '1e20',
        'digits beyond the integer range',
        ...self::NOTHING,
        ...self::DATES,
      ],
      '/when' => ['stored', ...$leaf, ...self::DATES],
      '/settings' => $map,
      '/settings/color' => ['stored', ...$leaf],
      '/tags' => $list,
      '/tags/0' => ['stored', ...$leaf],
      '/flag' => ['stored', 'stored in another spelling', ...self::LEAF, ...self::NOTHING],
      '/people' => [
        ...$list,
        'an item that is not a map',
        'omitted, a stored item that is not a map',
        'item secret pin stored, the item sent again',
        'item secret pin stored, another item sent before it',
        'item secret pin stored, three items sent',
        'item secret pin stored in two items, three sent',
      ],
      '/people/0' => $map,
      '/people/0/name' => ['stored', ...$leaf],
      '/people/0/pin' => [...$leaf, ...$secret],
      '/extra' => $map,
      '/extra/a' => ['stored', ...$leaf],
      '/lead' => $map,
      '/lead/email' => ['stored', ...$leaf],
      '/token' => ['stored', ...$leaf, ...$secret],
      '/fixed' => ['stored', ...$leaf, 'locked sent a value'],
      '/slug' => ['stored', ...$leaf, 'computed sent a value'],
      '/kind' => ['stored', ...$options, ...$leaf],
      '/credential' => [
        ...$map,
        'variant a',
        "variant a with another variant's keys",
        'variant b',
        "variant b with another variant's keys",
        'variant a secret key left for b',
        'variant a secret key kept',
        'variant a secret key aside, chosen again',
      ],
      '/credential/key' => ['stored', ...$leaf, ...$secret],
      '/credential/name' => [
        'default',
        ...$leaf,
        ...array_map(
          static fn (string $kind): string => "in variant b: $kind",
          ['stored', 'default', "'7'", '7', 'TRUE', "'yes'", "''", 'NULL', 'omitted'],
        ),
      ],
    ], $by);
    $this->assertSame(ValueCorpus::BASE, $names[0], 'The base case comes first.');
  }

  /**
   * Tests what each kind sends, and what it is accepted over.
   */
  public function testInputs(): void {
    $base = static::base();
    $cases = ValueCorpus::cases(static::document(), $base);
    $input = static fn (string $name): array => $cases[$name]['input'];
    $current = static fn (string $name): array => $cases[$name]['current'];

    // The whole surface.
    $this->assertSame($base, $input('/ echoed'));
    $this->assertSame([], $input('/ empty input'));
    $this->assertSame($base + [ValueCorpus::UNKNOWN => 'x'], $input('/ unknown key'));
    // cspell:ignore sizex
    $this->assertSame($base + ['sizex' => 'x'], $input('/ misspelled key'));

    // A leaf: its stored value, default and options, near misses of each,
    // the fixed values, omitted, an array and a JSON string.
    $this->assertSame(array_replace($base, ['size' => 'large']), $input('/size option 1'));
    $this->assertSame(array_replace($base, ['size' => 'LARGE']), $input('/size option 1 upper'));
    $this->assertSame(array_replace($base, ['size' => ' large ']), $input('/size option 1 spaced'));
    $this->assertSame('option N upper', $cases['/size option 1 upper']['kind']);
    $this->assertSame($base, $input('/count stored in another spelling'));
    $this->assertSame(array_replace($base, ['count' => '3']), $current('/count stored in another spelling'));
    $this->assertSame(array_replace($base, ['flag' => '1']), $current('/flag stored in another spelling'));
    $this->assertSame(array_replace($base, ['count' => 7.0]), $input('/count 7.0'));
    $this->assertSame(array_replace($base, ['count' => '7.5']), $input("/count '7.5'"));
    $this->assertSame(array_replace($base, ['count' => FALSE]), $input('/count FALSE'));
    $this->assertSame(array_replace($base, ['flag' => 1.0]), $input('/flag 1.0'));
    $this->assertSame(array_replace($base, ['flag' => 'yes']), $input("/flag 'yes'"));
    $this->assertSame(array_replace($base, ['count' => '-0']), $input("/count '-0'"));
    $this->assertSame(array_replace($base, ['count' => 1e20]), $input('/count 1e20'));
    $this->assertSame(array_replace($base, ['count' => '100000000000000000000']), $input('/count digits beyond the integer range'));
    $this->assertArrayNotHasKey('count', $input('/count omitted'));
    $this->assertSame(array_replace($base, ['count' => ['a']]), $input('/count array'));
    $this->assertSame(array_replace($base, ['count' => '{"a":1}']), $input('/count JSON string'));
    $this->assertSame(array_replace($base, ['when' => '2026-03-04 05:06:07+0200']), $input('/when datetime with a space and no colon'));
    $this->assertSame(array_replace($base, ['when' => '2026-03-04T00:00:00']), $input('/when datetime as stored at midnight'));
    $this->assertSame(array_replace($base, ['when' => 'not a date']), $input('/when not a date'));

    // Below a map and inside a list's item.
    $this->assertSame(array_replace($base, ['settings' => ['color' => 7]]), $input('/settings/color 7'));
    $this->assertSame(array_replace($base, ['tags' => [TRUE]]), $input('/tags/0 TRUE'));

    // A map and a list.
    $this->assertSame(array_replace($base, ['settings' => '{"color":"red"}']), $input('/settings JSON string'));
    $this->assertSame(array_replace($base, ['settings' => ['color' => 'red', ValueCorpus::UNKNOWN => 'x']]), $input('/settings unknown key'));
    $this->assertSame(array_replace($base, ['settings' => [['color' => 'red']]]), $input('/settings list where a map is expected'));
    $this->assertSame(array_replace($base, ['tags' => '["x"]']), $input('/tags JSON string'));
    $this->assertSame(array_replace($base, ['tags' => 'x']), $input('/tags bare item'));
    $this->assertSame(array_replace($base, ['tags' => ['x', NULL]]), $input('/tags null item'));
    $this->assertSame(array_replace($base, ['tags' => [NULL, 'x']]), $input('/tags null item first'));
    $this->assertSame(array_replace($base, ['tags' => ['x' => 'x', 'y' => 'x']]), $input('/tags keyed items'));

    $this->assertSame(array_replace($base, ['settings' => 7]), $input('/settings a number'));
    $this->assertSame(array_replace($base, ['settings' => 'x']), $current('/settings NULL over a stored string'));
    $this->assertSame(array_replace($base, ['tags' => '{"a":1}']), $input('/tags JSON map'));
    $omitted = $base;
    unset($omitted['count']);
    $this->assertSame($omitted, $input('/count omitted, not stored'));
    $this->assertSame($omitted, $current('/count omitted, not stored'));
    $this->assertSame($base + [ValueCorpus::UNKNOWN => 'x'], $current('/ echoed over an unknown key stored'));

    // A list of maps whose items hold a secret.
    $this->assertSame(array_replace($base, ['people' => [7]]), $input('/people an item that is not a map'));
    $this->assertSame(array_replace($base, ['people' => [7]]), $current('/people omitted, a stored item that is not a map'));
    $this->assertArrayNotHasKey('people', $input('/people omitted, a stored item that is not a map'));
    $stored = [['pin' => ValueCorpus::STORED_SECRET, 'name' => 'p']];
    $this->assertSame(array_replace($base, ['people' => [['name' => 'p']]]), $input('/people item secret pin stored, the item sent again'));
    $this->assertSame(array_replace($base, ['people' => $stored]), $current('/people item secret pin stored, the item sent again'));
    $this->assertSame(array_replace($base, ['people' => array_fill(0, 3, ['name' => 'p'])]), $input('/people item secret pin stored, three items sent'));
    $this->assertSame(array_replace($base, ['people' => [...$stored, ...$stored]]), $current('/people item secret pin stored in two items, three sent'));

    // A secret, over a stored secret and over none.
    $this->assertSame(array_replace($base, ['token' => SurfacePipelineInterface::CLEAR_SECRET]), $input('/token secret clear marker over stored'));
    $this->assertSame(array_replace($base, ['token' => ValueCorpus::STORED_SECRET]), $current('/token secret clear marker over stored'));
    $this->assertSame($base, $current('/token secret NULL over none'));
    $this->assertArrayNotHasKey('token', $input('/token secret omitted over none'));

    // A locked key and a computed key.
    $this->assertSame(array_replace($base, ['fixed' => 'f changed']), $input('/fixed locked sent a value'));
    $this->assertSame(array_replace($base, ['slug' => 's changed']), $input('/slug computed sent a value'));

    // A key with variants.
    $chosen = $input('/credential variant b');
    $this->assertSame('b', $chosen['kind']);
    $this->assertArrayNotHasKey('credential', $chosen);
    $this->assertSame(array_replace($base, ['kind' => 'a', 'credential' => ['name' => 'x']]), $input("/credential variant a with another variant's keys"));
    $left = '/credential variant a secret key left for b';
    $this->assertSame('b', $input($left)['kind']);
    $this->assertArrayNotHasKey('credential', $input($left));
    $this->assertSame(array_replace($base, ['kind' => 'a', 'credential' => ['key' => ValueCorpus::STORED_SECRET]]), $current($left));
    $this->assertSame(array_replace($base, ['kind' => 'a', 'credential' => []]), $input('/credential variant a secret key kept'));
    $aside = '/credential variant a secret key aside, chosen again';
    $this->assertSame(array_replace($base, ['kind' => 'b', 'credential' => ['key' => ValueCorpus::STORED_SECRET]]), $current($aside));
    $this->assertSame('a', $input($aside)['kind']);

    // A variant the base does not choose, asked under that variant, its
    // members at their defaults, over what the caller stores.
    $this->assertSame(array_replace($base, ['kind' => 'b', 'credential' => ['name' => 'n']]), $input('/credential/name in variant b: stored'));
    $this->assertSame(array_replace($base, ['kind' => 'b', 'credential' => ['name' => 7]]), $input('/credential/name in variant b: 7'));
    $this->assertSame($base, $current('/credential/name in variant b: 7'));
    $this->assertSame('in variant N: 7', $cases['/credential/name in variant b: 7']['kind']);
  }

  /**
   * Tests the cases a create is recorded with, over nothing stored.
   */
  public function testCreates(): void {
    $base = static::base();
    $cases = ValueCorpus::creates(static::document(), $base);
    $this->assertSame([[]], array_values(array_unique(array_column($cases, 'current'), SORT_REGULAR)), 'Nothing is stored for a create.');
    $this->assertSame($base, $cases[ValueCorpus::BASE]['input']);
    $this->assertSame([], $cases['/ empty input']['input']);
    $this->assertArrayNotHasKey('count', $cases['/count omitted']['input']);
    $this->assertSame([], $cases['/settings/color omitted']['input']['settings'], 'A place below a map is left out of its map.');
    $this->assertSame('s changed', $cases['/slug computed sent a value']['input']['slug']);
    $this->assertArrayNotHasKey('/credential/name omitted', $cases, 'A place the base holds nothing at is not left out.');
    $this->assertArrayNotHasKey('/people/0/pin omitted', $cases);
  }

  /**
   * Tests a later value set asks only the places it changes.
   *
   * The places its values differ at and below them, the places that
   * depend on one, and the places below one of those; the whole
   * surface's cases always.
   */
  public function testLaterValueSet(): void {
    $first = static::base();
    $pointers = static function (array $cases): array {
      $asked = [];
      foreach (array_keys($cases) as $name) {
        $asked[explode(' ', $name, 2)[0]] = TRUE;
      }
      return array_keys($asked);
    };
    $chosen = ['kind' => 'b', 'credential' => ['name' => 'n']] + $first;
    $this->assertSame(['/', '/lead/email', '/kind', '/credential', '/credential/key', '/credential/name'], $pointers(ValueCorpus::cases(static::document(), $chosen, $first)), 'The port /lead/email depends on reads kind, so it is asked.');
    $this->assertSame([], array_filter(array_keys(ValueCorpus::cases(static::document(), $chosen, $first)), static fn (string $name): bool => str_contains($name, ' in variant ')), 'A later value set asks no place under a variant it does not choose.');
    $sized = ['size' => 'large'] + $first;
    $this->assertSame(['/', '/size', '/settings/color', '/extra', '/extra/a', '/fixed'], $pointers(ValueCorpus::cases(static::document(), $sized, $first)));
    $opened = ValueCorpus::cases(static::document(), $sized, $first);
    $this->assertArrayHasKey('/extra/a 7', $opened, 'A place below an opened place is asked a second time.');
    $this->assertArrayNotHasKey('/extra/a 7.0', $opened, 'A place asked a second time is sent the second kinds alone.');
    $this->assertArrayHasKey('/settings/color 7.0', $opened, 'A place that depends on what changed is sent every kind.');
    $this->assertSame(['/'], $pointers(ValueCorpus::cases(static::document(), $first, $first)), 'A value set that changes nothing asks the whole surface alone.');
  }

  /**
   * Tests the corpus is the same every time it is asked.
   */
  public function testDeterministic(): void {
    $once = ValueCorpus::cases(static::document(), static::base());
    $this->assertSame($once, ValueCorpus::cases(static::document(), static::base()));
    $this->assertCount(616, $once);
  }

}
