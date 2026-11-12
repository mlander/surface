<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\DocumentHead;
use Drupal\surface\Contract\PropertySchema;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests an empty subschema below an applicator is written as given.
 *
 * The layout pass writes `true` only for a schema that said nothing but its
 * `x-surface` (directly or in the properties it holds); `{}` stays `{}`.
 *
 * Decision: see docs/decisions.md#the-emitter-builds-through-the-builder.
 */
#[Group('surface')]
final class DocumentHeadSettledTest extends UnitTestCase {

  /**
   * Each schema, and its layout.
   *
   * @return array<string, array{string, string}>
   *   The schema's JSON, and its layout's JSON.
   */
  public static function provideSchemas(): array {
    $if = '"if":{"properties":{"a":{"const":"x"}},"required":["a"]}';
    return [
      'then {}' => [
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{' . $if . ',"then":{}}]}',
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{' . $if . ',"then":{}}]}',
      ],
      'then.properties.b {}' => [
        '{"type":"object","properties":{"a":{"type":"string"},"b":{}},"allOf":[{' . $if . ',"then":{"properties":{"b":{}}}}]}',
        '{"type":"object","properties":{"a":{"type":"string"},"b":{}},"allOf":[{' . $if . ',"then":{"properties":{"b":{}}}}]}',
      ],
      'allOf [{}]' => [
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{}]}',
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{}]}',
      ],
      'items {} below then' => [
        '{"type":"object","properties":{"l":{"type":"array","items":{}}},"allOf":[{"if":{"properties":{"l":{"const":[]}},"required":["l"]},"then":{"properties":{"l":{"items":{},"maxItems":1}}}}]}',
        '{"type":"object","properties":{"l":{"type":"array","items":{}}},"allOf":[{"if":{"properties":{"l":{"const":[]}},"required":["l"]},"then":{"properties":{"l":{"type":"array","items":{},"maxItems":1}}}}]}',
      ],
      'not {}' => [
        '{"type":"object","allOf":[{"not":{}}]}',
        '{"type":"object","allOf":[{"not":{}}]}',
      ],
      'then holding only x-surface' => [
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{' . $if . ',"then":{"properties":{"b":{"x-surface":{"k":1}}}}}]}',
        '{"type":"object","properties":{"a":{"type":"string"}},"allOf":[{' . $if . ',"then":true}],"x-surface":{"within":{"/allOf/0/then/properties/b":{"k":1}}}}',
      ],
    ];
  }

  /**
   * Tests the layout of each schema.
   *
   * @param string $json
   *   The schema.
   * @param string $expected
   *   Its layout.
   */
  #[DataProvider('provideSchemas')]
  public function testEmptySubschemaIsKept(string $json, string $expected): void {
    // settled() reads nothing of the property schema.
    $propertySchema = (new \ReflectionClass(PropertySchema::class))->newInstanceWithoutConstructor();
    $head = new DocumentHead($propertySchema);
    $settled = (new \ReflectionMethod($head, 'settled'))->invoke($head, Schema::from(json_decode($json)));
    $this->assertSame($expected, json_encode($settled, JSON_UNESCAPED_SLASHES));
  }

}
