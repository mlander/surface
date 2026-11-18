<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the validator's own checks and the documents on the `narrow` surface.
 *
 * The `narrow` surface of the hand corpus, as HandCorpusTest runs the
 * others: choices narrowed, keys a refiner made required, ports in a
 * condition, computed keys and stale values. It is the largest of the
 * five, so it runs in a class of its own.
 *
 * Decision: see
 * docs/decisions.md#the-validators-checks-and-the-document-read-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class HandCorpusNarrowTest extends SurfaceKernelTestBase {

  use HandCorpusTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * Tests each row of the surface is as committed.
   */
  public function testEachRowIsAsCommitted(): void {
    $this->assertCorpus('narrow', 7257);
  }

}
