<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\Schemas;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests what the emitter adds to the builder's Schema.
 *
 * The array form encodes as the schema does, each object's PHP form chosen
 * by the shape of the keyword that holds it; first() prepends and
 * defaults() adds only what is missing, each in order, keeping a keyword
 * outside the vocabulary and refusing a boolean schema; same() compares by
 * JSON under the fixture flags and refuses what cannot be encoded; and
 * map() walks the subschemas and the transient shape's def, never a
 * keyword that holds data, in either order.
 *
 * Decision: see docs/decisions.md#the-emitter-builds-through-the-builder.
 */
#[Group('surface')]
final class SchemasTest extends UnitTestCase {

  /**
   * Tests the array form encodes as the schema does.
   */
  public function testArrayedEncodesAsTheSchema(): void {
    $schema = Schema::object()
      ->with('properties', ['empty' => Schema::object()->with('properties', []), 'any' => Schema::any(), 'no' => FALSE])
      ->with('x-surface', [
        'variants' => (object) ['0' => '#/$defs/a', '1' => '#/$defs/b'],
        'reads' => [],
        'by' => ['a' => 1],
      ])
      ->with('portable-check', ['drupal:Unique' => new \stdClass()]);
    $arrayed = Schemas::arrayed($schema);
    $this->assertIsArray($arrayed);
    $this->assertSame(json_encode($schema), json_encode($arrayed));
    $this->assertEquals(new \stdClass(), $arrayed['properties']['empty']['properties'], 'An empty map stays an object.');
    $this->assertEquals(new \stdClass(), $arrayed['properties']['any'], 'The empty schema stays an object.');
    $this->assertFalse($arrayed['properties']['no']);
    $this->assertInstanceOf(\stdClass::class, $arrayed['x-surface']['variants'], 'A map named by indexes stays an object.');
    $this->assertSame([], $arrayed['x-surface']['reads'], 'An empty list stays a list.');
    $this->assertSame(['a' => 1], $arrayed['x-surface']['by'], 'Any other object is an array.');
    $this->assertTrue(Schemas::arrayed(Schema::boolean(TRUE)));
  }

  /**
   * The flags the fixtures are written with.
   */
  private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

  /**
   * Each set of member names, and the PHP form of a map and of data.
   *
   * @return array<string, array{list<int|string>, bool, bool}>
   *   The names; whether a map of schemas named so is a \stdClass; whether
   *   a data object named so is.
   */
  public static function provideNames(): array {
    return [
      'empty' => [[], TRUE, TRUE],
      '0..n-1' => [[0, 1], TRUE, TRUE],
      '"1", "2"' => [[1, 2], FALSE, TRUE],
      '"0", "2"' => [[0, 2], FALSE, TRUE],
      'mixed names' => [['a', 1], FALSE, FALSE],
      'names' => [['a', 'b'], FALSE, FALSE],
    ];
  }

  /**
   * Tests the PHP form of an object follows the keyword that holds it.
   *
   * @param list<int|string> $names
   *   The member names.
   * @param bool $mapIsObject
   *   Whether a map of schemas named so is a \stdClass.
   * @param bool $dataIsObject
   *   Whether a data object named so is.
   */
  #[DataProvider('provideNames')]
  public function testTheFormFollowsTheKeyword(array $names, bool $mapIsObject, bool $dataIsObject): void {
    $members = (object) array_fill_keys($names, '#/$defs/a');
    $schema = Schema::object()
      ->with('properties', array_fill_keys($names, Schema::string()))
      ->with('$defs', array_fill_keys($names, Schema::string()))
      ->with('x-surface', ['variants' => $members])
      ->with('default', ['nested' => $members])
      ->with('x-portable-ui', ['options' => $members]);
    $arrayed = Schemas::arrayed($schema);
    $this->assertIsArray($arrayed);
    $this->assertSame(json_encode($schema->toDocument(), self::FLAGS), json_encode($arrayed, self::FLAGS), 'The array form encodes as the schema.');
    foreach (['properties' => $arrayed['properties'], '$defs' => $arrayed['$defs']] as $keyword => $map) {
      $this->assertSame($mapIsObject, $map instanceof \stdClass, $keyword . ' is a map of schemas.');
    }
    $data = [
      'x-surface.variants' => $arrayed['x-surface']['variants'],
      'default.nested' => $arrayed['default']['nested'],
      'x-portable-ui.options' => $arrayed['x-portable-ui']['options'],
    ];
    foreach ($data as $where => $value) {
      $this->assertSame($dataIsObject, $value instanceof \stdClass, $where . ' is data.');
    }
    if (!$mapIsObject) {
      $this->assertSame(array_map('strval', $names), array_map('strval', array_keys($arrayed['properties'])), 'An associative array keeps its names.');
    }
  }

  /**
   * Tests same() compares under the fixture flags and refuses what fails.
   */
  public function testSameEncodesStrictly(): void {
    $this->assertFalse(Schemas::same(1, 1.0), '1 and 1.0 differ on the wire.');
    $this->assertTrue(Schemas::same(new \stdClass(), new \stdClass()), 'Two empty objects are the same.');
    $this->assertFalse(Schemas::same(new \stdClass(), []), 'An empty object is not an empty list.');
    foreach (['NAN' => [NAN, NAN], 'invalid UTF-8' => ["\xB1a", "\xB1b"]] as $which => [$a, $b]) {
      try {
        Schemas::same($a, $b);
        $this->fail($which . ' cannot be encoded.');
      }
      catch (\JsonException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Tests first() and defaults() keep a foreign keyword, refuse a boolean.
   */
  public function testFirstAndDefaultsReadAsFromDoes(): void {
    $read = Schema::from(['type' => 'string', 'nullable' => TRUE]);
    $first = Schemas::first($read, ['title' => 'X']);
    $this->assertSame(['title' => 'X', 'type' => 'string', 'nullable' => TRUE], $first->all());
    $defaults = Schemas::defaults($read, ['title' => 'X']);
    $this->assertSame(['type' => 'string', 'nullable' => TRUE, 'title' => 'X'], $defaults->all());
    $this->assertSame($read, Schemas::defaults($read, ['type' => 'integer']), 'Nothing to add: the schema itself.');
    foreach (['first', 'defaults'] as $helper) {
      try {
        Schemas::{$helper}(Schema::boolean(TRUE), ['title' => 'X']);
        $this->fail($helper . '() refuses a boolean schema.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('Schemas::' . $helper . '()', $e->getMessage());
      }
    }
  }

  /**
   * Tests first() prepends and defaults() adds only what is missing.
   */
  public function testFirstAndDefaultsKeepTheirOrder(): void {
    $schema = Schema::keywords(['type' => 'string', 'title' => 'Name', 'x-surface' => ['a' => 1]]);
    $first = Schemas::first($schema, ['readOnly' => TRUE, 'title' => 'Given']);
    $this->assertSame(['readOnly', 'title', 'type', 'x-surface'], array_keys($first->all()));
    $this->assertSame('Given', $first->get('title'), 'A keyword given first replaces the schema\'s own.');
    $defaults = Schemas::defaults($schema, ['title' => 'Other', 'minLength' => 1, 'maxLength' => 2]);
    $this->assertSame(['type', 'title', 'x-surface', 'minLength', 'maxLength'], array_keys($defaults->all()));
    $this->assertSame('Name', $defaults->get('title'), 'A keyword the schema states keeps its value.');
    $this->assertSame($schema, Schemas::first($schema, []));
  }

  /**
   * Tests two schemas are the same when their JSON is.
   */
  public function testSameComparesTheJson(): void {
    $named = Schema::string()->with('title', 'A');
    $this->assertTrue(Schemas::same($named, Schema::from(['type' => 'string', 'title' => 'A'])));
    $this->assertFalse(Schemas::same($named, Schema::from(['title' => 'A', 'type' => 'string'])), 'Order is part of what a schema says.');
    $this->assertTrue(Schemas::same([Schema::null()], [['type' => 'null']]), 'A value holding schemas compares as its JSON.');
  }

  /**
   * Tests map() reaches every subschema and no data, in either order.
   */
  public function testMapReachesSubschemasAndNoData(): void {
    $data = ['title' => 'data'];
    $schema = Schema::keywords([
      'title' => 'root',
      'properties' => ['a' => ['title' => 'map']],
      'items' => ['title' => 'single'],
      'allOf' => [['title' => 'list']],
      'x-surface-shape' => ['id' => 'shape', 'def' => Schema::keywords(['title' => 'shape'])],
      'default' => $data,
      'const' => $data,
      'examples' => [$data],
      'portable-check' => ['drupal:JsonSchema' => ['schema' => $data]],
      'x-portable-messages' => ['title' => 'data'],
      'x-surface' => $data,
      'x-portable-ui' => ['options' => $data],
    ]);
    foreach ([TRUE, FALSE] as $bottomUp) {
      $seen = [];
      $mapped = Schemas::map($schema, static function (Schema $node) use (&$seen): Schema {
        $seen[] = $node->get('title');
        return $node->with('title', $node->get('title') . '!');
      }, $bottomUp);
      $expected = ['map', 'single', 'list', 'shape'];
      $this->assertSame($bottomUp ? [...$expected, 'root'] : ['root', ...$expected], $seen, $bottomUp ? 'Bottom up.' : 'Top down.');
      $this->assertSame('shape!', $mapped->get('x-surface-shape')['def']->get('title'), 'The transient def is rewritten in place.');
      $this->assertSame(['id', 'def'], array_keys($mapped->get('x-surface-shape')));
      foreach (['default', 'const', 'portable-check', 'x-portable-messages', 'x-surface', 'x-portable-ui'] as $keyword) {
        $this->assertSame($schema->get($keyword), $mapped->get($keyword), $keyword . ' holds data.');
      }
      $this->assertSame([$data], $mapped->get('examples'));
      $this->assertSame(array_keys($schema->all()), array_keys($mapped->all()), 'Every keyword keeps its place.');
    }
    $false = Schema::boolean(FALSE);
    $this->assertSame($false, Schemas::map($false, static fn (Schema $node): Schema => Schema::any()), 'A boolean schema is left as it is.');
  }

  /**
   * Tests at() steps by the shape of each keyword.
   */
  public function testAtStepsByTheKeywordsShape(): void {
    $schema = Schema::from(json_decode(<<<'JSON'
      {"type": "object", "properties": {"a/b": {"type": "string"}, "~x": true, "0": {"type": "integer"}},
       "allOf": [{"if": {"required": ["a/b"]}, "then": {"properties": {"0": false}}}],
       "x-surface": {"by": "kind", "variants": {"k": "#/$defs/k"}},
       "default": {"properties": {"x": 1}}}
      JSON));
    $this->assertSame($schema, Schemas::at($schema, ''), 'The empty pointer is the schema.');
    $this->assertSame('string', Schemas::at($schema, '/properties/a~1b')->get('type'), 'A map step is a name, ~1 a slash.');
    $this->assertTrue(Schemas::boolean(Schemas::at($schema, '/properties/~0x')), 'A name with a tilde, a boolean schema.');
    $this->assertSame('integer', Schemas::at($schema, '/properties/0')->get('type'), 'A name that looks like an index.');
    $this->assertFalse(Schemas::boolean(Schemas::at($schema, '/allOf/0/then/properties/0')), 'A list step is an index.');
    $this->assertSame(['a/b'], Schemas::at($schema, '/allOf/0/if/required'), 'A value is the value.');
    $this->assertSame('#/$defs/k', Schemas::at($schema, '/x-surface/variants/k'), 'A step into a value reads its members.');
    $this->assertSame(1, Schemas::at($schema, '/default/properties/x'), 'Data shaped like a schema stays data.');
    $this->assertNotInstanceOf(Schema::class, Schemas::at($schema, '/default'));
    foreach (['/properties/missing', '/allOf/1', '/allOf/first', '/x-surface/none', '/type/0', 'properties'] as $missing) {
      $this->assertNull(Schemas::at($schema, $missing), $missing . ': nothing is there.');
    }
    $this->assertNull(Schemas::at(Schema::boolean(TRUE), '/type'), 'A boolean schema holds no keyword.');
  }

  /**
   * Tests decoded() writes what JSON decodes, and the two schema answers.
   */
  public function testDecodedAndTheBooleans(): void {
    $document = json_decode('{"type": "object", "properties": {}, "items": {}, "x-surface": {"variants": {"0": "#/a"}}, "default": {}, "allOf": [true, {"const": 1.0}]}');
    $schema = Schema::from($document);
    $decoded = Schemas::decoded($schema);
    $this->assertSame(json_decode((string) json_encode($document, JSON_PRESERVE_ZERO_FRACTION), TRUE), $decoded, 'What json_decode() gives, objects as arrays.');
    $this->assertSame(1.0, $decoded['allOf'][1]['const'], 'A float stays a float.');
    $this->assertFalse(Schemas::decoded(Schema::boolean(FALSE)));
    $this->assertTrue(Schemas::boolean(Schema::boolean(TRUE)));
    $this->assertNull(Schemas::boolean(Schema::any()), 'An object schema is no boolean.');
    $this->assertNull(Schemas::boolean(TRUE), 'Nor is a value that is no schema.');
    $this->assertTrue(Schemas::isObjectSchema(Schema::any()), 'An empty object schema holds keywords.');
    $this->assertFalse(Schemas::isObjectSchema(Schema::boolean(TRUE)));
    $this->assertTrue(Schemas::same(Schema::from(['not' => ['type' => 'null']]), ['not' => ['type' => 'null']]), 'A schema and the array it is say the same.');
    $this->assertFalse(Schemas::same(Schema::from(['not' => ['type' => 'null']]), ['not' => ['type' => 'string']]));
  }

}
