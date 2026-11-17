<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the value baseline of the block placement create, less its parts.
 *
 * @see \Drupal\Tests\surface\Kernel\ValueBaselineTestBase
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ValueBaselinePlacementTest extends ValueBaselineTestBase {

  /**
   * {@inheritdoc}
   */
  protected const GROUP = 'placement';

  /**
   * {@inheritdoc}
   */
  protected const COUNTS = [
    'surfaces' => 1,
    'value sets' => 33,
    'cases' => 11031,
    'accepted' => 10307,
  ];

}
