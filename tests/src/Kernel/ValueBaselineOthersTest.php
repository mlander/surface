<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the value baseline of every data surface no other group holds.
 *
 * @see \Drupal\Tests\surface\Kernel\ValueBaselineTestBase
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ValueBaselineOthersTest extends ValueBaselineTestBase {

  /**
   * {@inheritdoc}
   */
  protected const GROUP = 'others';

  /**
   * {@inheritdoc}
   */
  protected const COUNTS = [
    'surfaces' => 55,
    'value sets' => 182,
    'cases' => 22592,
    'accepted' => 16965,
  ];

}
