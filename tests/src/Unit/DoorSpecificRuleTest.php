<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;
use Drupal\Tests\data_surface\PHPStan\DoorSpecificRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the PHPStan rule that flags what an operation runs reading a door's.
 *
 * @extends \PHPStan\Testing\RuleTestCase<\Drupal\Tests\data_surface\PHPStan\DoorSpecificRule>
 *
 * @see docs/resources.md#no-door-specific-context
 */
#[Group('data_surface')]
final class DoorSpecificRuleTest extends RuleTestCase {

  /**
   * {@inheritdoc}
   */
  protected function getRule(): Rule {
    return new DoorSpecificRule();
  }

  /**
   * Tests the door-specific reads are flagged, and only they.
   */
  public function testFlagsDoorSpecificReads(): void {
    // Loaded first: PHPStan's own source locator traps the file it reads,
    // which the test runner's debug class loader does not expect.
    class_exists(RefinesInput::class);
    class_exists(KeyedList::class);
    interface_exists(KeysFromSiteInterface::class);
    interface_exists(DataDefinitionInterface::class);
    $tail = ': a door-specific service makes the doors disagree, since the form, the HTTP API and the tool each hand it something else. ';
    $account = 'Use the account the operation hands in: an access class\'s $account, a storage verb\'s $account, a runtime refiner\'s parameter typed AccountInterface.';
    $context = 'Everything an operation needs comes through it: the parameters and identity its context knows, the values, and site state through a service whose answer carries its cacheability.';
    $refiner = 'Drupal\Tests\data_surface\Fixtures\DoorSpecificFixture';
    $keys = 'Drupal\Tests\data_surface\Fixtures\DoorSpecificKeysFixture';
    $fixtures = dirname(__DIR__, 2) . '/fixtures/phpstan/';
    $files = [
      $fixtures . 'DoorSpecificFixture.php',
      $fixtures . 'DoorSpecificKeysFixture.php',
      $fixtures . 'DoorSpecificDoorFixture.php',
    ];
    $this->analyse($files, [
      [
        "The refiner's class $refiner::readsTheCurrentUser() calls \\Drupal::currentUser(), the service current_user, which is bound to the account a request signed in as" . $tail . $account,
        20,
      ],
      [
        "The provider $keys::__construct() is built with Symfony\\Component\\HttpFoundation\\RequestStack \$requestStack, the service request_stack, which is bound to the request a door is answering" . $tail . $context,
        21,
      ],
      [
        "The provider $keys::create() asks the container for session, the service session, which is bound to the session of the request" . $tail . $context,
        28,
      ],
      [
        "The provider $keys::create() asks the container for request_stack, the service request_stack, which is bound to the request a door is answering" . $tail . $context,
        29,
      ],
      [
        "The provider $keys::keys() calls \\Drupal::routeMatch(), the service current_route_match, which is bound to the route a door is answering" . $tail . $context,
        37,
      ],
    ]);
  }

  /**
   * Tests the rule refuses what the guarded container refuses.
   */
  public function testRefusesWhatTheGuardRefuses(): void {
    $this->assertSame(PipelineGuard::DOOR_SPECIFIC, DoorSpecificRule::SERVICES);
  }

}
