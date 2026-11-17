<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the value baseline of the other block data surfaces.
 *
 * @see \Drupal\Tests\surface\Kernel\ValueBaselineTestBase
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ValueBaselineBlocksTest extends ValueBaselineTestBase {

  /**
   * {@inheritdoc}
   */
  protected const GROUP = 'blocks';

  /**
   * {@inheritdoc}
   */
  protected const COUNTS = [
    'surfaces' => 5,
    'value sets' => 42,
    'cases' => 11393,
    'accepted' => 10927,
  ];

}
