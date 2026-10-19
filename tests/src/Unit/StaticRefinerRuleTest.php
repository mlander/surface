<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\Tests\data_surface\PHPStan\StaticRefinerRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the PHPStan rule that flags a static refiner reaching for services.
 *
 * @extends \PHPStan\Testing\RuleTestCase<\Drupal\Tests\data_surface\PHPStan\StaticRefinerRule>
 *
 * @see docs/refinement.md#enforcing-the-kind
 */
#[Group('data_surface')]
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
    $message = 'The static refiner Drupal\Tests\data_surface\Fixtures\StaticRefinerFixture::%s() calls \Drupal::%s(), which reads a service or site state. A static refiner is a pure function of the keys it watches: point at a list with a constraint, or declare #[RefinesInput(..., runtime: TRUE)].';
    // Loaded first: PHPStan's own source locator traps the file it reads,
    // which the test runner's debug class loader does not expect.
    class_exists(RefinesInput::class);
    interface_exists(DataDefinitionInterface::class);
    $this->analyse([dirname(__DIR__, 2) . '/fixtures/phpstan/StaticRefinerFixture.php'], [
      [sprintf($message, 'readsState', 'state'), 20],
      [sprintf($message, 'asksForService', 'service'), 28],
    ]);
  }

}
