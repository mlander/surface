<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\DeriverInterface;
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

  /**
   * Tests a deriver that calls \Drupal:: is flagged, and nothing else of it.
   */
  public function testFlagsDeriverReachingForTheContainer(): void {
    interface_exists(DeriverInterface::class);
    $message = 'The deriver Drupal\Tests\data_surface\Fixtures\DeriverFixture::derive() calls \Drupal::time(), which reads a service or site state. A computed key is a pure function of the keys it is computed from: a value that needs a service, the clock or the request is a storage\'s to add in its prepare().';
    $this->analyse([dirname(__DIR__, 2) . '/fixtures/phpstan/DeriverFixture.php'], [[$message, 18]]);
  }

}
