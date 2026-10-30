<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Holds the first and second resources to the invariants, as an adopter would.
 *
 * The proof of ResourceTestBase on this module's own pages: the
 * newsletter and the store location, the three things an adopter writes
 * and nothing else. The store's region is one of the chosen country's, a
 * coded refiner, so its full schema is reported as not round-tripping,
 * by kind.
 *
 * @see docs/testing-a-resource.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FirstResourceInvariantsTest extends ResourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['surface_examples_first'];

  /**
   * {@inheritdoc}
   */
  protected static array $resources = ['first.newsletter', 'first.store'];

  /**
   * {@inheritdoc}
   */
  protected static array $values = [
    'first.store' => [
      'a region of the chosen country' => [['country' => 'US', 'region' => 'US-NY'], TRUE],
      'a region of another country' => [['country' => 'US', 'region' => 'CA-ON'], FALSE],
    ],
    'first.newsletter' => [
      'a monthly send' => [
        ['sender_name' => 'News desk', 'sender_email' => 'news@example.com', 'frequency' => 'monthly'],
        TRUE,
      ],
      'more recipients than a send takes' => [
        ['sender_name' => 'News desk', 'sender_email' => 'news@example.com', 'max_recipients' => 20000],
        FALSE,
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static string $baselines = __DIR__ . '/../../fixtures/contracts';

}
