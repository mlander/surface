<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Flow;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StagedInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * The itinerary's update with its second step drawn under a condition.
 *
 * The region, then the ledger row while the stage holds more than two
 * nights, then the day and the nights. No route of this module names it;
 * StageStoreSwapTest names it on the class flow's route, to see the
 * resume offer link past a second step passed over.
 */
#[Flow('stage_test.skips_second_step', label: 'Edit a trip in steps')]
final class SkipsSecondStepFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region');
    $steps->step('ledger', 'stage_test.ledger', 'update', ['id' => 'row'])
      ->when(static fn (StagedInterface $staged): bool => (int) $staged->value('stage_test.itinerary', 'nights') > 2);
    $steps->step('when', 'stage_test.itinerary', 'update')
      ->keys('day', 'nights');
  }

}
