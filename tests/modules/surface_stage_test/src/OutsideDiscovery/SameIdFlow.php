<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\OutsideDiscovery;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused: it gives the id ItineraryFlow gives.
 *
 * Outside discovery: FlowClassTest hands it to a flow registry it builds.
 */
#[Flow('stage_test.itinerary_flow', label: 'The same id again')]
final class SameIdFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region');
  }

}
