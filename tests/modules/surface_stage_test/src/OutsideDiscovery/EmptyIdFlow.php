<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\OutsideDiscovery;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused: its #[Flow] gives an empty id.
 *
 * Outside discovery: FlowClassTest hands it to a flow registry it builds.
 */
#[Flow('', label: 'No id')]
final class EmptyIdFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region');
  }

}
