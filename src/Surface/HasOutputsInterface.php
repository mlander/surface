<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

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
 *
 * An output carries no default, which the engine refuses rather than
 * ignores: a default is what a value starts from when nobody sent one,
 * and nobody sends an output.
 */
interface HasOutputsInterface {

  /**
   * Shape of what the surface answers with.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $outputs
   *   The output shape to fill.
   */
  public function defineOutputs(ShapeInterface $outputs): void;

}
