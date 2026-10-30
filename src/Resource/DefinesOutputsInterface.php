<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * A resource or structure that answers with something too.
 *
 * Optional, because most only ask. Same tool as inputs.
 * Outputs are never refined, because a refinement narrows what may be
 * sent and nobody sends an output; a #[RefinesInput] naming an output key is
 * refused at seal. An output whose keys depend on an input value is a
 * variant: attachVariants() on that input key, exactly as for input variants.
 *
 * Inputs and outputs are separate namespaces; a key may appear in both
 * with different meanings.
 *
 * An output carries no default, which the engine refuses rather than
 * ignores: a default is what a value starts from when nobody sent one,
 * and nobody sends an output.
 */
interface DefinesOutputsInterface {

  /**
   * Outputs. What the resource or structure answers with.
   *
   * Static, like DefinesInputsInterface::defineInputs(), and for the same
   * reason: it takes nothing but the blueprint to fill.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $outputs
   *   The output blueprint to fill.
   */
  public static function defineOutputs(BlueprintInterface $outputs): void;

}
