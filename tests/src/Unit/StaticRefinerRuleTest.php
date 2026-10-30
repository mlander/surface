<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\ComputesValueInterface;
use Drupal\Tests\surface\PHPStan\StaticRefinerRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the PHPStan rule that flags a static refiner reaching for services.
 *
 * @extends \PHPStan\Testing\RuleTestCase<\Drupal\Tests\surface\PHPStan\StaticRefinerRule>
 *
 * @see docs/refinement.md#enforcing-the-kind
 */
#[Group('surface')]
final class StaticRefinerRuleTest extends RuleTestCase {

  /**
   * {@inheritdoc}
   */
  protected function getRule(): Rule {
    return new StaticRefinerRule();
  }

  /**
   * Tests the static refiners that call \Drupal:: are flagged, and only they.
   */
  public function testFlagsStaticRefinersReachingForTheContainer(): void {
    $message = 'The static refiner Drupal\Tests\surface\Fixtures\StaticRefinerFixture::%s() calls \Drupal::%s(), which reads a service or site state. A static refiner is a pure function of the keys it watches: point at a list with a constraint, or declare #[RefinesInput(..., runtime: TRUE)].';
    // Loaded first: PHPStan's own source locator traps the file it reads,
    // which the test runner's debug class loader does not expect.
    class_exists(RefinesInput::class);
    interface_exists(DataDefinitionInterface::class);
    $this->analyse([dirname(__DIR__, 2) . '/fixtures/phpstan/StaticRefinerFixture.php'], [
      [sprintf($message, 'readsState', 'state'), 20],
      [sprintf($message, 'asksForService', 'service'), 28],
    ]);
  }

  /**
   * Tests a compute() that calls \Drupal:: is flagged, and nothing else of it.
   */
  public function testFlagsComputeReachingForTheContainer(): void {
    interface_exists(DataDefinitionInterface::class);
    interface_exists(ComputesValueInterface::class);
    $message = 'The computed key\'s Drupal\Tests\surface\Fixtures\ComputeFixture::compute() calls \Drupal::time(), which reads a service or site state. A computed key is a pure function of the keys it is computed from: a value that needs a service, the clock or the request is a storage\'s to add in its prepare().';
    $this->analyse([dirname(__DIR__, 2) . '/fixtures/phpstan/ComputeFixture.php'], [[$message, 19]]);
  }

}
