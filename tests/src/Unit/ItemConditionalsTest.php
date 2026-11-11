<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Form\Document\DocumentDrawer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests how the drawer reads a collection's items' conditionals per item.
 *
 * An `if` is judged only where the reader can read it: `required`, and a
 * key's `const` or `enum` under `properties`. One that says anything else
 * (`not`, a `type`) is skipped, never counted as holding. A key `if`
 * names and the item leaves out meets it, as JSON Schema reads it, unless
 * it states a default, which it is read at; values compare as JSON
 * values.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
#[Group('surface')]
class ItemConditionalsTest extends UnitTestCase {

  /**
   * An items def with a key each conditional narrows.
   *
   * @param array $if
   *   The `if` of the one conditional.
   *
   * @return \PortableSchema\Builder\Schema
   *   The items' object: `unit`, and `height`, which a conditional that
   *   holds caps at 10.
   */
  protected static function items(array $if): Schema {
    return Schema::from([
      'type' => 'object',
      'properties' => [
        'unit' => ['type' => ['string', 'null']],
        'height' => ['type' => 'integer', 'maximum' => 100],
      ],
      'allOf' => [
        ['if' => $if, 'then' => ['properties' => ['height' => ['maximum' => 10]]]],
      ],
    ]);
  }

  /**
   * Reads the height's maximum for one item.
   *
   * @param array $if
   *   The conditional's `if`.
   * @param array $item
   *   The item's values.
   *
   * @return mixed
   *   10 where the conditional held, 100 where it did not.
   */
  protected static function maximum(array $if, array $item): mixed {
    return DocumentDrawer::narrowedFor(static::items($if), $item)->get('properties')['height']->get('maximum');
  }

  /**
   * Tests an `if` the reader cannot read is skipped, never held.
   */
  public function testAnUnreadableIfIsSkipped(): void {
    $this->assertSame(100, static::maximum(['not' => ['properties' => ['unit' => ['const' => 'cm']]]], ['unit' => 'm']), 'A `not` is skipped.');
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['type' => 'string']]], ['unit' => 'm']), 'A key\'s `type` is skipped.');
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['const' => 'cm', 'pattern' => '^c']]], ['unit' => 'cm']), 'A keyword beside `const` is skipped.');
    $this->assertFalse(DocumentDrawer::holds(Schema::from(['not' => ['required' => ['unit']]]), []));
    $this->assertFalse(DocumentDrawer::holds(Schema::from(TRUE), []), 'A boolean `if` is not read.');
  }

  /**
   * Tests `const` and `enum` read as JSON Schema reads them.
   */
  public function testConstAndEnum(): void {
    $this->assertSame(10, static::maximum(['properties' => ['unit' => ['const' => 'cm']], 'required' => ['unit']], ['unit' => 'cm']));
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['const' => 'cm']], 'required' => ['unit']], ['unit' => 'm']));
    $this->assertSame(10, static::maximum(['properties' => ['unit' => ['enum' => ['cm', 'mm']]]], ['unit' => 'mm']));
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['enum' => ['cm', 'mm']]]], ['unit' => 'm']));
    // An absent key meets its `const`: the subschema holds for a key that
    // is not there, and `required` is what asks for it.
    $this->assertSame(10, static::maximum(['properties' => ['unit' => ['const' => 'cm']]], []));
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['const' => 'cm']], 'required' => ['unit']], []));
    $this->assertSame(100, static::maximum(['properties' => ['unit' => ['const' => 'cm']]], ['unit' => NULL]), 'A key holding null does not meet a string.');
    // A key the item leaves out that states a default is read at it, as
    // it is drawn.
    $defaulted = Schema::from([
      'type' => 'object',
      'properties' => [
        'unit' => ['type' => 'string', 'default' => 'm'],
        'height' => ['type' => 'integer', 'maximum' => 100],
      ],
      'allOf' => [
        [
          'if' => ['properties' => ['unit' => ['const' => 'cm']], 'required' => ['unit']],
          'then' => ['properties' => ['height' => ['maximum' => 10]]],
        ],
      ],
    ]);
    $this->assertSame(100, DocumentDrawer::narrowedFor($defaulted, [])->get('properties')['height']->get('maximum'));
    $this->assertSame(10, DocumentDrawer::narrowedFor($defaulted, ['unit' => 'cm'])->get('properties')['height']->get('maximum'));
    // As JSON values: a number equals a number, nothing else equals one.
    $this->assertTrue(DocumentDrawer::holds(Schema::from(['properties' => ['n' => ['const' => 1]]]), ['n' => 1.0]));
    $this->assertFalse(DocumentDrawer::holds(Schema::from(['properties' => ['n' => ['const' => FALSE]]]), ['n' => '0']));
    $this->assertFalse(DocumentDrawer::holds(Schema::from(['properties' => ['n' => ['const' => '1']]]), ['n' => 1]));
  }

}
