<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests DefinitionSchema::withPropertyAt() replaces the schema at its path.
 *
 * A nested property is replaced where the path ends, and every map on the
 * way is kept: its other properties and its `required` list (mutant C4).
 */
#[Group('surface')]
class WithPropertyAtTest extends UnitTestCase {

  /**
   * Tests the place at the end of the path is replaced, and nothing above.
   */
  public function testReplacesAtTheEndOfThePath(): void {
    $schema = Schema::object()
      ->property('outer', Schema::object()
        ->property('inner', Schema::string())
        ->property('beside', Schema::bool())
        ->with('required', ['inner']))
      ->property('top', Schema::string());
    $placed = DefinitionSchema::withPropertyAt($schema, ['outer', 'inner'], Schema::integer());

    $outer = $placed->get('properties')['outer'];
    $this->assertSame('object', $outer->get('type'), 'The map on the way stays a map.');
    $this->assertSame(['inner'], $outer->get('required'));
    $this->assertSame(['inner', 'beside'], array_keys($outer->get('properties')));
    $this->assertSame('integer', $outer->get('properties')['inner']->get('type'));
    $this->assertSame('boolean', $outer->get('properties')['beside']->get('type'));
    $this->assertSame('string', $placed->get('properties')['top']->get('type'));
    $this->assertSame('integer', DefinitionSchema::withPropertyAt($schema, ['top'], Schema::integer())->get('properties')['top']->get('type'));
  }

}
