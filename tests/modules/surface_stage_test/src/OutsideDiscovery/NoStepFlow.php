<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\OutsideDiscovery;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused: it declares no step.
 *
 * Outside discovery: FlowClassTest hands it to a flow registry it builds.
 */
#[Flow('stage_test.no_step', label: 'No step')]
final class NoStepFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    // Declares nothing.
  }

}
