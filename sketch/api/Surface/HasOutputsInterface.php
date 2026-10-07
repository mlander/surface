<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * A surface that answers with something, beyond accepting values.
 *
 * Optional, because most surfaces only ask. Same tool as inputs.
 * Outputs are never refined, because a refinement narrows what may be
 * sent and nobody sends an output; a #[RefinesInput] naming an output key is
 * refused at seal. An output whose shape depends on an input value is a
 * variant: attachBy() on that input key, exactly as for an input slot.
 *
 * Inputs and outputs are separate namespaces; a key may appear in both
 * with different meanings.
 */
interface HasOutputsInterface {

  public function defineOutputs(ShapeInterface $outputs): void;

}
