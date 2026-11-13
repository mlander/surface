<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the block placement create's value sets that fill a slot.
 *
 * @see \Drupal\Tests\surface\Kernel\ValueBaselineTestBase
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ValueBaselinePlacementSlotsTest extends ValueBaselineTestBase {

  /**
   * {@inheritdoc}
   */
  protected const GROUP = 'placement slots';

  /**
   * {@inheritdoc}
   */
  protected const COUNTS = [
    'surfaces' => 1,
    'value sets' => 13,
    'cases' => 6131,
    'accepted' => 5910,
  ];

}
