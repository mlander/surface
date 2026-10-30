<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Holds example 14's mailer and webhook to the invariants.
 *
 * Both keep their endpoint in a `uri` key, which the generated rows send
 * an array: the door check is where an array reaching core's primitive
 * type validator through the Tool API's validation was found, a
 * TypeError where a refusal belongs. Every door now refuses it alike.
 * With the module that manages keys on, as the committed baselines are.
 *
 * @see docs/testing-a-resource.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesSecretsInvariantsTest extends ResourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['surface_examples', 'surface_examples_keys'];

  /**
   * {@inheritdoc}
   */
  protected static array $resources = ['event.mailer', 'event.webhook'];

  /**
   * {@inheritdoc}
   */
  protected static array $values = [
    'event.mailer' => [
      'an endpoint sent as a list' => [['endpoint' => ['x']], FALSE],
    ],
    'event.webhook' => [
      'an endpoint sent as a list' => [['endpoint' => ['x']], FALSE],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static string $baselines = __DIR__ . '/../../fixtures/contracts';

}
