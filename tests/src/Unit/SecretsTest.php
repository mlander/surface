<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\Secrets;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Tool\InputDefinitions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;

/**
 * Tests what Secrets reads as secret, from a schema and from a document.
 *
 * The rules: a schema is secret where it says `writeOnly: true`; a place in
 * a document is secret where any schema that applies there is, through
 * `$ref` (any pointer into the document, or an id of the registry),
 * `allOf`, `anyOf`, `oneOf`, `then`, `else` and `dependentSchemas`,
 * reached through `properties`, `patternProperties`,
 * `additionalProperties`, `items` and `prefixItems`, and never through
 * what holds data or what only tests a value (`if`, `not`, `contains`); a
 * list is not secret because its items are; a `$ref` that resolves to
 * nothing is secret. absent() answers with a margin, for a door that need
 * not build what holds no secret.
 *
 * @see docs/decisions.md#a-shape-is-a-schema-secrecy-is-read-from-a-schema
 */
#[Group('surface')]
class SecretsTest extends UnitTestCase {

  /**
   * A secret value.
   */
  protected static function secret(): Schema {
    return Schema::string()->writeOnly(TRUE);
  }

  /**
   * The type library, with one site type that holds a type that is secret.
   *
   * @return \PortableSchema\Registry
   *   The registry: `https://example.com/vault` is an object whose `pin` is
   *   `portable:secret`.
   */
  protected static function library(): Registry {
    return Registry::withTypes()->add((object) [
      'type' => 'object',
      'properties' => (object) [
        'pin' => (object) ['$ref' => 'portable:secret'],
        'label' => (object) ['type' => 'string'],
      ],
    ], 'https://example.com/vault');
  }

  /**
   * Tests a schema says it is secret by its own `writeOnly` alone.
   */
  public function testMarked(): void {
    $this->assertTrue(Secrets::marked(static::secret()));
    $this->assertFalse(Secrets::marked(Schema::string()));
    $this->assertFalse(Secrets::marked(Schema::string()->writeOnly(FALSE)));
    $this->assertFalse(Secrets::marked(Schema::boolean(TRUE)));
    $this->assertFalse(Secrets::marked(Schema::object(['pin' => static::secret()])), 'What is inside is not the schema.');
  }

  /**
   * Lists a document, a place in it and whether the place is secret.
   *
   * @return array<string, array{0: \PortableSchema\Builder\Schema, 1: string, 2: bool}>
   *   The cases.
   */
  public static function places(): array {
    $secret = static::secret();
    $string = Schema::string();
    $pin = Schema::object(['pin' => $secret]);
    $by = Schema::object(['kind' => $string]);
    $data = ['writeOnly' => TRUE];
    $if = Schema::any()->with('if', $pin)->with('then', Schema::any());
    $prefixed = Schema::any()->with('prefixItems', [$string, $secret])->items($string);
    $past = Schema::any()->with('prefixItems', [$secret])->items($string);
    $rows = Schema::object(['rows' => Schema::list(Schema::of('#/$defs/row'))])
      ->def('row', Schema::object(['token' => $secret]));
    $nested = Schema::object(['x' => Schema::of('#/$defs/a/$defs/b')])->def('a', Schema::any()->def('b', $secret));
    $self = static fn (array $more): Schema => Schema::object(['a' => Schema::of('#/$defs/a')])
      ->def('a', Schema::object(['next' => Schema::of('#/$defs/a')] + $more));
    $vault = Schema::object(['v' => Schema::of('https://example.com/vault')]);
    $keyed = static fn (Schema $member): Schema => Schema::object([])->with('patternProperties', ['^key_' => $member]);
    return [
      'the root' => [$secret, '', TRUE],
      'a property' => [$pin, '/pin', TRUE],
      'a plain property' => [Schema::object(['pin' => $secret, 'name' => $string]), '/name', FALSE],
      'a place no schema names' => [$pin, '/other', FALSE],
      'a def by $ref' => [Schema::object(['pin' => Schema::of('#/$defs/pin')])->def('pin', $secret), '/pin', TRUE],
      'a def inside a def' => [$nested, '/x', TRUE],
      'any pointer into the document' => [
        Schema::object(['x' => Schema::of('#/properties/y'), 'y' => $secret]),
        '/x',
        TRUE,
      ],
      'a $ref cycle' => [$self([]), '/a/next/next', FALSE],
      'a $ref cycle that says secret' => [$self(['pin' => $secret]), '/a/next/pin', TRUE],
      'a pointer to nothing' => [Schema::object(['x' => Schema::of('#/$defs/missing')]), '/x', TRUE],
      'an id the registry does not hold' => [Schema::object(['x' => Schema::of('portable:nope')]), '/x', TRUE],
      'a site type that holds a type that is secret' => [$vault, '/v/pin', TRUE],
      'a site type, a plain place in it' => [$vault, '/v/label', FALSE],
      'the root by #' => [Schema::object(['self' => Schema::of('#')])->writeOnly(TRUE), '/self', TRUE],
      'a type of the library' => [Schema::object(['password' => Schema::of('portable:secret')]), '/password', TRUE],
      'allOf' => [Schema::object(['pin' => Schema::allOf([$string, Schema::any()->writeOnly(TRUE)])]), '/pin', TRUE],
      'anyOf, one branch' => [Schema::object(['pin' => Schema::anyOf([$secret, Schema::null()])]), '/pin', TRUE],
      'oneOf' => [Schema::object(['pin' => Schema::oneOf([Schema::integer(), $secret])]), '/pin', TRUE],
      'then' => [$by->when(['kind' => 'a'], $pin), '/pin', TRUE],
      'else' => [$by->when(['kind' => 'a'], Schema::object([]), $pin), '/pin', TRUE],
      'not if' => [Schema::object([])->with('allOf', [$if]), '/pin', FALSE],
      'not not' => [Schema::object([])->with('not', $pin), '/pin', FALSE],
      'not contains' => [Schema::any()->with('contains', $secret), '/0', FALSE],
      'patternProperties' => [$keyed($secret), '/key_a', TRUE],
      'patternProperties, a name it does not match' => [$keyed($secret), '/other', FALSE],
      'patternProperties beside additionalProperties' => [
        $keyed($string)->additionalProperties($secret),
        '/key_a',
        FALSE,
      ],
      'dependentSchemas' => [
        Schema::object(['kind' => $string])->with('dependentSchemas', ['kind' => $pin]),
        '/pin',
        TRUE,
      ],
      'additionalProperties' => [Schema::map($secret), '/anything', TRUE],
      'additionalProperties beside a property' => [
        Schema::object(['name' => $string])->additionalProperties($secret),
        '/name',
        FALSE,
      ],
      'an item' => [Schema::object(['tokens' => Schema::list($secret)]), '/tokens/0', TRUE],
      'a list of secrets is not one' => [Schema::object(['tokens' => Schema::list($secret)]), '/tokens', FALSE],
      'prefixItems' => [$prefixed, '/1', TRUE],
      'items past prefixItems' => [$past, '/1', FALSE],
      'an item of a collection, by $ref' => [$rows, '/rows/3/token', TRUE],
      'a name escaped' => [Schema::object(['a/b' => $secret]), '/a~1b', TRUE],
      'default' => [Schema::object(['note' => Schema::string()->default($data)]), '/note', FALSE],
      'examples' => [Schema::object(['note' => Schema::string()->examples([$data])]), '/note', FALSE],
      'const' => [Schema::object(['note' => Schema::const($data)]), '/note', FALSE],
      'enum' => [Schema::object(['note' => Schema::enum([$data])]), '/note', FALSE],
    ];
  }

  /**
   * Tests a place is secret where any schema that applies there is.
   */
  #[DataProvider('places')]
  public function testAt(Schema $document, string $pointer, bool $secret): void {
    $this->assertSame($secret, Secrets::at($document, $pointer, static::library()));
  }

  /**
   * Tests within() builds the tree Outbound::secrets() builds.
   */
  public function testWithin(): void {
    $secret = static::secret();
    $library = Registry::withTypes();
    $this->assertSame([], Secrets::within(Schema::object(['name' => Schema::string()]), $library));
    $this->assertTrue(Secrets::within($secret, $library));
    $document = Schema::object([
      'name' => Schema::string(),
      'pin' => $secret,
      'password' => Schema::of('portable:secret'),
      'tokens' => Schema::list($secret),
      'rows' => Schema::list(Schema::of('#/$defs/row')),
      'plain' => Schema::list(Schema::string()),
      'settings' => Schema::object(['inner' => Schema::object(['key' => $secret])]),
      'note' => Schema::string()->default(['writeOnly' => TRUE]),
      'credential' => Schema::anyOf([Schema::of('#/$defs/pasted'), Schema::of('#/$defs/managed'), Schema::null()]),
    ])->def('row', Schema::object(['token' => $secret, 'label' => Schema::string()]))
      ->def('pasted', Schema::object(['api_key' => $secret]))
      ->def('managed', Schema::object(['key_id' => Schema::string(), 'api_key' => Schema::string()]));
    $this->assertSame([
      'pin' => TRUE,
      'password' => TRUE,
      'tokens' => TRUE,
      'rows' => [Outbound::EACH => ['token' => TRUE]],
      'settings' => ['inner' => ['key' => TRUE]],
      'credential' => ['api_key' => TRUE],
    ], Secrets::within($document, $library), 'A key one variant keeps secret is secret under that key.');
    $this->assertSame(Outbound::EACH, Secrets::EACH);
    $this->assertSame(InputDefinitions::EACH, Secrets::EACH);
  }

  /**
   * Lists documents within() reads, and the tree of each.
   *
   * @return array<string, array{0: \PortableSchema\Builder\Schema, 1: true|array}>
   *   The cases.
   */
  public static function trees(): array {
    $secret = static::secret();
    $string = Schema::string();
    $by = Schema::object(['kind' => $string]);
    $pin = Schema::object(['pin' => $secret]);
    $tuple = static fn (array $prefix): Schema => Schema::object([
      't' => Schema::any()->type('array')->with('prefixItems', $prefix),
    ]);
    $patterned = Schema::object(['m' => Schema::object([])->with('patternProperties', ['^k' => $secret])]);
    return [
      'then' => [$by->when(['kind' => 'a'], Schema::object(['pin' => $secret])), ['pin' => TRUE]],
      'oneOf' => [Schema::object(['pin' => Schema::oneOf([Schema::integer(), $secret])]), ['pin' => TRUE]],
      'prefixItems that hold a secret: the list is secret whole' => [$tuple([$string, $pin]), ['t' => TRUE]],
      'prefixItems that hold none' => [$tuple([$string]), []],
      'additionalProperties that are secret: the object is secret whole' => [
        Schema::object(['m' => Schema::map($secret)]),
        ['m' => TRUE],
      ],
      'additionalProperties that hold a secret' => [Schema::object(['m' => Schema::map($pin)]), ['m' => TRUE]],
      'additionalProperties that hold none' => [Schema::object(['m' => Schema::map($string)]), []],
      'patternProperties that hold a secret' => [$patterned, ['m' => TRUE]],
      'dependentSchemas' => [
        Schema::object(['kind' => $string])->with('dependentSchemas', ['kind' => $pin]),
        ['pin' => TRUE],
      ],
      'a pointer to nothing' => [Schema::object(['x' => Schema::of('#/$defs/missing')]), ['x' => TRUE]],
    ];
  }

  /**
   * Tests within() ends on a def that holds itself, each level said.
   */
  public function testWithinEndsOnCycle(): void {
    $document = Schema::object(['a' => Schema::of('#/$defs/a')])
      ->def('a', Schema::object(['next' => Schema::of('#/$defs/a'), 'pin' => static::secret()]));
    $tree = Secrets::within($document, static::library());
    $this->assertIsArray($tree);
    $this->assertTrue($tree['a']['pin']);
    $this->assertTrue($tree['a']['next']['next']['pin']);
  }

  /**
   * Tests within() through each construct it enters.
   */
  #[DataProvider('trees')]
  public function testWithinEachConstruct(Schema $document, true|array $tree): void {
    $this->assertSame($tree, Secrets::within($document, static::library()));
  }

  /**
   * Tests absent() answers with a margin: any `writeOnly`, any shape's def.
   */
  public function testAbsent(): void {
    $none = static fn (string $id): bool => FALSE;
    $library = Registry::withTypes();
    $shelf = Schema::object(['heading' => Schema::string()->maxLength(80), 'finish' => Schema::enum(['oak', 'ash'])]);
    $this->assertTrue(Secrets::absent(Schema::any()->def('poster.shelf.0', $shelf)->def('poster.shelf.1', $shelf), $none, $library));
    $deep = Schema::any()->def('deep', Schema::any()->with('properties', ['inner' => Schema::object(['pin' => static::secret()])]));
    $this->assertFalse(Secrets::absent($deep, $none, $library), 'At any depth, in any def.');
    // A def a shape is known by may be secret in the shape's own words.
    $shaped = Schema::of('#/$defs/event.license')->def('event.license', Schema::string());
    $this->assertTrue(Secrets::absent($shaped, $none, $library));
    $this->assertFalse(Secrets::absent($shaped, static fn (string $id): bool => $id === 'event.license', $library));
    $this->assertFalse(Secrets::absent(Schema::of('#/$defs/elsewhere'), static fn (string $id): bool => $id === 'elsewhere', $library), 'A $ref names a def as its $defs does.');
    // A type of the library that says it.
    $typed = Schema::object([
      'password' => Schema::of('portable:secret'),
      'note' => Schema::string()->default(['writeOnly' => TRUE]),
    ]);
    $this->assertFalse(Secrets::absent($typed, $none, $library));
    $this->assertTrue(Secrets::absent(Schema::object(['note' => Schema::string()->default(['writeOnly' => TRUE])]), $none, $library), 'writeOnly inside a default makes no secret.');
    $this->assertTrue(Secrets::absent(Schema::object(['email' => Schema::of('portable:email')]), $none, $library), 'A type of the library that says none.');
  }

}
