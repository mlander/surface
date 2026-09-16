<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Pipeline\Omitted;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The sentinel that says an output key is not emitted.
 *
 * Three claims, and the first is the one everything else rests on:
 * Omitted is not NULL and is not any value a producer could
 * accidentally have meant. NULL is a value — emitted, and held to the
 * definition — and absence is the other thing entirely.
 *
 * @see \Drupal\data_surface\Pipeline\Omitted
 * @see docs/outputs.md
 */
#[Group('data_surface')]
class OmittedTest extends UnitTestCase {

  /**
   * Tests that the sentinel is one object, recognized by identity.
   */
  public function testTheSentinelIsOneObject(): void {
    $this->assertSame(Omitted::value(), Omitted::value());
    $this->assertTrue(Omitted::is(Omitted::value()));
  }

  /**
   * Tests that nothing a producer could emit is mistaken for it.
   *
   * NULL above all: the whole point of having a sentinel is that a key
   * whose value is NULL has been emitted and a key that is omitted has
   * not, and no consumer can tell them apart if NULL is the marker.
   */
  public function testNothingElseIsTheSentinel(): void {
    foreach ([NULL, FALSE, 0, '', '0', [], 'Omitted', new \stdClass()] as $value) {
      $this->assertFalse(Omitted::is($value), sprintf('%s is not the sentinel.', get_debug_type($value)));
    }
  }

  /**
   * Tests that a copy of the sentinel cannot be made.
   */
  public function testTheSentinelCannotBeCloned(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('recognized by identity');
    // @phpstan-ignore expr.resultUnused
    clone Omitted::value();
  }

  /**
   * Tests that stripping removes omitted keys and keeps everything else.
   */
  public function testStrippingKeepsEveryValueAndDropsEveryAbsence(): void {
    $this->assertSame([
      'text' => 'hello',
      'empty' => '',
      'nothing' => NULL,
      'false' => FALSE,
      'list' => [],
    ], Omitted::strip([
      'text' => 'hello',
      'empty' => '',
      'nothing' => NULL,
      'false' => FALSE,
      'list' => [],
      'gone' => Omitted::value(),
    ]));
  }

  /**
   * Tests that stripping reaches every depth.
   *
   * A map output's properties are outputs too, so a producer marks the
   * property it has nothing to say about rather than the whole map.
   */
  public function testStrippingReachesNestedKeys(): void {
    $this->assertSame(
      ['meta' => ['count' => 2, 'deeper' => ['kept' => TRUE]]],
      Omitted::strip([
        'meta' => [
          'count' => 2,
          'tag' => Omitted::value(),
          'deeper' => ['kept' => TRUE, 'gone' => Omitted::value()],
        ],
      ]),
    );
  }

  /**
   * Tests that a list closes the gap an omitted item leaves.
   *
   * A list with nothing at delta 1 is not a list any consumer expects,
   * and a producer that omits one item of a list means a shorter list
   * rather than a list with a hole in it.
   */
  public function testStrippingReindexesLists(): void {
    $this->assertSame(
      ['tags' => ['a', 'c']],
      Omitted::strip(['tags' => ['a', Omitted::value(), 'c']]),
    );
    // A map whose keys happen to be strings keeps every one of them.
    $this->assertSame(
      ['map' => ['b' => 2]],
      Omitted::strip(['map' => ['a' => Omitted::value(), 'b' => 2]]),
    );
  }

}
