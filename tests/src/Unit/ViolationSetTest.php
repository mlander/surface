<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\SurfaceViolation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the collection every refusal in the module arrives in.
 *
 * What the pipeline, a target and a config schema each report is the
 * same three facts — a surface key, a path inside it, a message — and
 * this is the one shape they report them in, so a form, a tool result
 * and a log line read one object rather than remembering an array of
 * arrays.
 *
 * Two rules are load-bearing. Iteration is grouped by key, in the order
 * the keys were first refused, because a summary reads the violations
 * in that order and a schema may hand them over interleaved. And a
 * message is never rendered here: it stays the object its constraint
 * built, so whatever prints it escapes it exactly once.
 *
 * @see \Drupal\data_surface\Pipeline\ViolationSet
 * @see \Drupal\data_surface\Pipeline\SurfaceViolation
 */
#[Group('data_surface')]
class ViolationSetTest extends UnitTestCase {

  /**
   * Tests the empty set, which is what a valid run answers with.
   */
  public function testEmptySet(): void {
    $set = new ViolationSet();

    $this->assertTrue($set->isEmpty());
    $this->assertCount(0, $set);
    $this->assertSame([], $set->keys());
    $this->assertSame([], $set->byKey('title'));
    $this->assertFalse($set->has('title'));
    $this->assertSame([], iterator_to_array($set));
  }

  /**
   * Tests that violations are read back by the key they were filed under.
   */
  public function testByKey(): void {
    $first = new SurfaceViolation('title', '', 'Too long.');
    $second = new SurfaceViolation('title', 'sub', 'Not a choice.');
    $other = new SurfaceViolation('limit', '', 'Too large.');
    $set = new ViolationSet([$first, $second, $other]);

    $this->assertFalse($set->isEmpty());
    $this->assertCount(3, $set);
    $this->assertSame(['title', 'limit'], $set->keys());
    $this->assertSame([$first, $second], $set->byKey('title'));
    $this->assertSame([$other], $set->byKey('limit'));
    $this->assertTrue($set->has('limit'));
    $this->assertFalse($set->has('nothing'));
    $this->assertSame([], $set->byKey('nothing'));
  }

  /**
   * Tests that iteration groups by key, whatever order they arrived in.
   *
   * A config schema validates a whole object and reports its complaints
   * in its own order, which can interleave two surface keys. What a
   * summary prints has to be every violation of one key together, in
   * the order that key was first refused.
   */
  public function testIterationIsGroupedByKey(): void {
    $set = new ViolationSet([
      new SurfaceViolation('title', '', 'First.'),
      new SurfaceViolation('limit', '', 'Second.'),
      new SurfaceViolation('title', '', 'Third.'),
    ]);

    $messages = array_map(
      static fn (SurfaceViolation $violation): string => (string) $violation->message,
      iterator_to_array($set),
    );
    $this->assertSame(['First.', 'Third.', 'Second.'], $messages);
    $this->assertSame(['title', 'limit'], $set->keys());
  }

  /**
   * Tests the full path a violation names, with and without a sub-path.
   */
  public function testFullPath(): void {
    $this->assertSame('title', (new SurfaceViolation('title', '', 'Refused.'))->fullPath());
    $this->assertSame(
      'extras.badge',
      (new SurfaceViolation('extras', 'badge', 'Refused.'))->fullPath(),
    );
  }

  /**
   * Tests that a message stays the object the constraint built.
   *
   * Rendering it on the way in would substitute its placeholders once,
   * as plain text, and whatever printed the violation afterwards would
   * escape that text a second time.
   */
  public function testMessagesStayObjects(): void {
    $message = new TranslatableMarkup('@label is required.', ['@label' => 'Title']);
    $set = new ViolationSet([new SurfaceViolation('title', '', $message)]);

    $this->assertSame($message, $set->byKey('title')[0]->message);
  }

}
