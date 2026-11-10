<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\OutsideDiscovery;

use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * A flow whose stage is kept short and whose apply redirects.
 *
 * Outside discovery: FlowClassTest hands it to a flow registry it builds.
 */
#[Flow('stage_test.kept_short', label: 'A short trip', keep: 'short', redirect: 'surface_stage_test.itinerary_update')]
final class KeptShortFlow implements FlowInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region');
  }

}
