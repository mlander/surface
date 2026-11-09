<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Flow;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused when a route names it: its first step carries arguments.
 *
 * No route of this module names it; FlowClassTest names it on a route it
 * builds and hands the router's subscriber.
 */
#[Flow('stage_test.first_step_arguments', label: 'A trip by its id')]
final class FirstStepArgumentsFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update', ['id' => 'trip'])
      ->keys('region');
  }

}
