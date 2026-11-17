<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\OutputsDocument;
use Drupal\surface\Contract\Schemas;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests what an output's schema keeps in the outputs' validation document.
 *
 * @see \Drupal\surface\Contract\OutputsDocument::held()
 */
#[Group('surface')]
class OutputsDocumentTest extends UnitTestCase {

  /**
   * Tests the checks stay, the presentation goes and each object is closed.
   */
  public function testHeld(): void {
    $written = Schema::from([
      'title' => 'Meta',
      'type' => ['object', 'null'],
      'x-surface' => ['widget' => 'details'],
      'x-portable-ui' => ['widget' => 'details'],
      'properties' => [
        'user' => [
          'type' => 'integer',
          'portable-check' => ['drupal:SurfaceValidReference' => []],
          'x-portable-messages' => ['check.drupal:SurfaceValidReference' => 'No such user.'],
          'x-portable-links' => ['lookup' => ['href' => '/lookup']],
        ],
        'inner' => [
          'type' => 'object',
          'properties' => ['code' => ['type' => 'string']],
        ],
        'rows' => [
          'type' => 'array',
          'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ],
        'stated' => [
          'type' => 'object',
          'properties' => ['a' => ['type' => 'string']],
          'additionalProperties' => ['type' => 'string'],
        ],
        'open' => ['title' => 'Open'],
      ],
    ]);
    $held = Schemas::arrayed(OutputsDocument::held($written));

    $this->assertArrayNotHasKey('x-surface', $held);
    $this->assertArrayNotHasKey('x-portable-ui', $held);
    $this->assertFalse($held['additionalProperties']);
    $user = $held['properties']['user'];
    $this->assertSame(['drupal:SurfaceValidReference' => []], $user['portable-check']);
    $this->assertSame(['check.drupal:SurfaceValidReference' => 'No such user.'], $user['x-portable-messages']);
    $this->assertArrayNotHasKey('x-portable-links', $user);
    $this->assertFalse($held['properties']['inner']['additionalProperties']);
    $this->assertFalse($held['properties']['rows']['items']['additionalProperties']);
    // A word of its own on other keys is kept, and a schema naming no keys
    // is not closed.
    $this->assertSame(['type' => 'string'], $held['properties']['stated']['additionalProperties']);
    $this->assertSame(['title' => 'Open'], $held['properties']['open']);
  }

  /**
   * Tests a boolean schema is left as it is.
   */
  public function testBooleanSchema(): void {
    $this->assertTrue(OutputsDocument::held(Schema::from(TRUE))->isBoolean());
  }

}
