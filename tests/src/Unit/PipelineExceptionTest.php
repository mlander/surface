<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Pipeline\ShapeMismatchException;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two refusals accept() raises rather than guessing.
 *
 * Both exist because silence is the expensive answer: a misspelled key
 * that is dropped and a map sent where a number was advertised both end
 * as data that is not there, discovered later by somebody who cannot
 * tell what happened. Both therefore have to say enough to point at the
 * exact place in a nested payload, which is what these tests pin: the
 * accessors a caller branches on, and the message a log line or an API
 * response actually carries.
 *
 * The message is also the one boundary where a list becomes a sentence,
 * so it is capped. These messages reach logs, and a payload with two
 * hundred misspelled keys would otherwise produce a two hundred item
 * sentence that every log line truncates in the middle; the full list
 * stays on the exception for a caller that wants it.
 *
 * @see \Drupal\data_surface\Pipeline\UnknownKeysException
 * @see \Drupal\data_surface\Pipeline\ShapeMismatchException
 */
#[Group('data_surface')]
class PipelineExceptionTest extends UnitTestCase {

  /**
   * Tests an undeclared key at the surface's own top level.
   */
  public function testUnknownKeysAtTheTopLevel(): void {
    $exception = new UnknownKeysException(['bogus']);

    $this->assertSame(['bogus'], $exception->getKeys());
    // The empty string, not NULL: the top level is a path, and a caller
    // concatenating it needs a string either way.
    $this->assertSame('', $exception->getPath());
    // No "under" clause, because there is no level to name.
    $this->assertSame('Unknown key(s) bogus.', $exception->getMessage());
    // Refused input is a caller's mistake, which is what the class it
    // extends says to anything catching broadly.
    $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
  }

  /**
   * Tests that a nested level is named in the message and the accessor.
   */
  public function testUnknownKeysUnderNestedPath(): void {
    $exception = new UnknownKeysException(['bogus', 'worse'], 'extras.deeper');

    $this->assertSame(['bogus', 'worse'], $exception->getKeys());
    $this->assertSame('extras.deeper', $exception->getPath());
    $this->assertSame(
      'Unknown key(s) bogus, worse under extras.deeper.',
      $exception->getMessage(),
    );
  }

  /**
   * Tests that a long list is capped in the message and not on the object.
   */
  public function testUnknownKeysMessageIsCapped(): void {
    $keys = ['k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7'];
    $exception = new UnknownKeysException($keys);

    $this->assertSame(
      'Unknown key(s) k1, k2, k3, k4, k5, +2 more.',
      $exception->getMessage(),
    );
    // Capping is for the sentence. Every key is still on the exception.
    $this->assertSame($keys, $exception->getKeys());
    $this->assertCount(ViolationSummary::LIMIT + 2, $exception->getKeys());
  }

  /**
   * Tests that exactly the cap is named without a tail.
   */
  public function testUnknownKeysMessageAtExactlyTheCap(): void {
    $keys = array_map(static fn (int $i): string => 'k' . $i, range(1, ViolationSummary::LIMIT));

    $this->assertSame(
      'Unknown key(s) k1, k2, k3, k4, k5.',
      (new UnknownKeysException($keys))->getMessage(),
    );
  }

  /**
   * Tests the shape mismatch's accessors and sentence.
   */
  public function testShapeMismatchNamesPathExpectedAndActual(): void {
    $exception = new ShapeMismatchException('extras.weight', 'integer', 'array');

    $this->assertSame('extras.weight', $exception->getPath());
    $this->assertSame('integer', $exception->getExpected());
    $this->assertSame('array', $exception->getActual());
    $this->assertSame(
      'The value at "extras.weight" must be of type integer, array given.',
      $exception->getMessage(),
    );
    $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
  }

  /**
   * Tests the mismatch a top level key raises.
   *
   * The path starts at a surface key rather than at the empty string,
   * because a value always belongs to one: there is no shape to mismatch
   * above the keys themselves.
   */
  public function testShapeMismatchAtTopLevelKey(): void {
    $exception = new ShapeMismatchException('extras', 'map', 'string');

    $this->assertSame('extras', $exception->getPath());
    $this->assertSame(
      'The value at "extras" must be of type map, string given.',
      $exception->getMessage(),
    );
  }

}
