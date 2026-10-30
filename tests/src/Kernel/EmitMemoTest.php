<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Structure\Booth\BoothStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * One emit refines each combination once, however many keys watch it.
 *
 * Static refiners are pure functions of what they watch (answersAgain()
 * holds them to it), so two keys enumerated over one sibling share the
 * refines: the booth's width and depth both follow its three sizes.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EmitMemoTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_resource_test',
  ];

  /**
   * Tests that the full schema refines each size once for both keys.
   */
  public function testEachCombinationIsRefinedOnce(): void {
    $surface = $this->container->get('surface.resources')->buildStatic(BoothStructure::class, new OperationContext('configure'));
    $emitter = $this->container->get('surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    $before = $emitter->refineCounts();
    $document = $emitter->emitFull($surface, 'resource_test.booth', 'configure')->document;
    $after = $emitter->refineCounts();
    // Three sizes: refined for width, remembered for depth.
    $this->assertSame(3, $after['refined'] - $before['refined']);
    $this->assertSame(3, $after['reused'] - $before['reused']);

    // Both keys still state each size's ceiling where it narrows.
    $conditionals = json_encode($document['schema']['allOf'] ?? []);
    $this->assertIsString($conditionals);
    foreach (['width', 'depth'] as $key) {
      foreach ([2, 4] as $max) {
        $this->assertStringContainsString('"' . $key . '":{"type":["integer","null"],"maximum":' . $max . '}', $conditionals);
      }
    }

    // The memo is the document's: a second one refines afresh.
    $emitter->emitFull($surface, 'resource_test.booth', 'configure');
    $this->assertSame(3, $emitter->refineCounts()['refined'] - $after['refined']);
  }

}
