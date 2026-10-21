<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\Rule;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests unless(): when() of the condition's negation, one condition still.
 *
 * What the full schema writes for it is DeclaredNarrowingTest's: the
 * parcel's note is ruled out unless the service is freight.
 *
 * @see docs/decisions.md#a-rule-declared-as-data
 */
#[Group('data_surface')]
class RuleUnlessTest extends UnitTestCase {

  /**
   * Tests unless() states the condition's negation.
   */
  public function testUnlessIsWhenNot(): void {
    $condition = Condition::equals('after_hours', TRUE);
    $unless = (new Rule('door_code'))->unless($condition)->doesNotApply();
    $when = (new Rule('door_code'))->when(Condition::not($condition))->doesNotApply();

    $this->assertEquals($when->condition(), $unless->condition());
    $this->assertSame(['not' => $condition->schema()], $unless->condition()?->schema());
    $this->assertTrue($unless->condition()->holds(['after_hours' => FALSE]));
    $this->assertFalse($unless->condition()->holds(['after_hours' => TRUE]));
    $this->assertSame(['after_hours'], $unless->condition()->keys());
  }

  /**
   * Tests a rule has one condition, whichever word gave it.
   */
  public function testOneConditionEitherWay(): void {
    foreach ([
      'unless after when' => static fn () => (new Rule('note'))->when(Condition::present('a'))->unless(Condition::present('b')),
      'when after unless' => static fn () => (new Rule('note'))->unless(Condition::present('a'))->when(Condition::present('b')),
      'unless twice' => static fn () => (new Rule('note'))->unless(Condition::present('a'))->unless(Condition::present('b')),
    ] as $label => $call) {
      try {
        $call();
        $this->fail($label . ' was accepted.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString('narrow("note") was given a second condition.', $e->getMessage(), $label);
      }
    }
  }

}
