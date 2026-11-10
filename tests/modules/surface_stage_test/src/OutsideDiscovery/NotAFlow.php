<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Flow;

/**
 * Refused: it carries #[Flow] and does not implement FlowInterface.
 *
 * Outside discovery: FlowClassTest hands it to a flow registry it builds.
 */
#[Flow('stage_test.not_a_flow', label: 'Not a flow')]
final class NotAFlow {

}
