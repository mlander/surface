<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Flow;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused when a route names it: its first step is drawn under a condition.
 *
 * No route of this module names it; FlowClassTest names it on a route it
 * builds and hands the router's subscriber.
 */
#[Flow('stage_test.first_step_condition', label: 'A trip, sometimes')]
final class FirstStepConditionFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region')
      ->when(static fn (): bool => TRUE);
  }

}
