<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\Conditionals;
use Drupal\surface\Contract\PropertySchema;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests a narrowing restates only what encodes differently.
 *
 * Conditionals::narrowed() and PropertySchema::narrowing() compare each
 * keyword by its JSON (Schemas::same()): two empty `properties` maps and two
 * empty `default` objects are the same, so neither is restated; `1` and
 * `1.0` are different, so the refined one is.
 *
 * Decision: see docs/decisions.md#the-emitter-builds-through-the-builder.
 */
#[Group('surface')]
final class NarrowingComparedByJsonTest extends UnitTestCase {

  /**
   * Each refined schema, its base, and what the narrowing says.
   *
   * @return array<string, array{\PortableSchema\Builder\Schema, \PortableSchema\Builder\Schema, string}>
   *   The refined schema, the base, and the narrowing's JSON.
   */
  public static function provideCorners(): array {
    return [
      'both properties empty' => [
        Schema::object()->with('properties', []),
        Schema::object()->with('properties', []),
        '{}',
      ],
      'both default {}' => [
        Schema::object()->with('default', new \stdClass()),
        Schema::object()->with('default', new \stdClass()),
        '{}',
      ],
      '1 against 1.0' => [
        Schema::number()->with('maximum', 1.0),
        Schema::number()->with('maximum', 1),
        '{"maximum":1.0}',
      ],
    ];
  }

  /**
   * Tests Conditionals::narrowed() on each corner.
   */
  #[DataProvider('provideCorners')]
  public function testNarrowed(Schema $schema, Schema $base, string $expected): void {
    $conditionals = new Conditionals(self::propertySchema());
    $narrowed = (new \ReflectionMethod($conditionals, 'narrowed'))->invoke($conditionals, $schema, $base);
    $this->assertSame($expected, json_encode($narrowed, JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Tests PropertySchema::narrowing() on each corner.
   */
  #[DataProvider('provideCorners')]
  public function testNarrowing(Schema $schema, Schema $base, string $expected): void {
    $narrowing = self::propertySchema()->narrowing($schema, $base, []);
    $this->assertSame($expected, json_encode($narrowing, JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Builds a property schema for what these comparisons read of it.
   *
   * @return \Drupal\surface\Contract\PropertySchema
   *   One with no services: narrowed() and narrowing() use none.
   */
  private static function propertySchema(): PropertySchema {
    return (new \ReflectionClass(PropertySchema::class))->newInstanceWithoutConstructor();
  }

}
