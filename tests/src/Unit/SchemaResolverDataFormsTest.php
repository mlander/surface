<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests SchemaResolver reads a Schema's data in either form.
 *
 * Schema::from() of JSON decoded with objects as arrays holds `x-surface`
 * and `x-portable-ui` as arrays; of JSON decoded with objects as
 * \stdClass, as objects. Both read the same.
 *
 * @see \Drupal\surface\Contract\SchemaResolver
 */
#[Group('surface')]
class SchemaResolverDataFormsTest extends UnitTestCase {

  /**
   * Reads a document both ways.
   *
   * @param array $document
   *   The document, objects as arrays.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}
   *   The document read from arrays, and read from objects.
   */
  protected static function bothForms(array $document): array {
    $objects = json_decode(json_encode($document, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
    return [Schema::from($document), Schema::from($objects)];
  }

  /**
   * Tests restored() puts the markers back from objects as from arrays.
   */
  public function testRestoredReadsObjects(): void {
    [$arrays, $objects] = static::bothForms([
      'type' => 'object',
      'properties' => ['a' => ['type' => 'string']],
      'allOf' => [
        [
          'if' => ['properties' => ['a' => ['const' => 'x']], 'required' => ['a']],
          'then' => ['properties' => ['a' => ['minLength' => 2]]],
        ],
      ],
      'x-surface' => ['rules' => [0], 'within' => ['/properties/a' => ['group' => 'g']]],
    ]);
    $restored = json_encode(SchemaResolver::restored($objects), JSON_THROW_ON_ERROR);
    $this->assertSame(json_encode(SchemaResolver::restored($arrays), JSON_THROW_ON_ERROR), $restored);
    $this->assertStringContainsString('"a":{"type":"string","x-surface":{"group":"g"}}', $restored, 'The marker is back on its key.');
    $this->assertStringContainsString('"x-surface":{"declared":true}', $restored, 'The rule is marked.');
    $this->assertStringNotContainsString('within', $restored);
  }

  /**
   * Tests merge() joins hints and groups held as objects.
   */
  public function testMergeReadsObjects(): void {
    [$base_arrays, $base_objects] = static::bothForms([
      'type' => 'object',
      'x-portable-ui' => ['widget' => 'w', 'groups' => ['g' => ['label' => 'G']]],
      'x-surface' => ['doesNotApply' => ['b']],
    ]);
    [$over_arrays, $over_objects] = static::bothForms([
      'x-portable-ui' => ['groups' => ['h' => ['label' => 'H']]],
      'x-surface' => ['group' => TRUE],
    ]);
    $merged = json_encode(SchemaResolver::merge($base_objects, $over_objects), JSON_THROW_ON_ERROR);
    $this->assertSame(json_encode(SchemaResolver::merge($base_arrays, $over_arrays), JSON_THROW_ON_ERROR), $merged);
    $this->assertSame('{"type":"object","x-portable-ui":{"widget":"w","groups":{"g":{"label":"G"},"h":{"label":"H"}}},"x-surface":{"group":true,"doesNotApply":["b"]}}', $merged);
  }

}
