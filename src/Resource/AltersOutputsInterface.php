<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * An alter that adds outputs. Optional, as HasOutputsInterface is.
 */
interface AltersOutputsInterface {

  /**
   * Adds outputs to the resource or structure named on #[Alters].
   *
   * @param \Drupal\data_surface\Resource\BlueprintAdditionsInterface $outputs
   *   The output blueprint, add-only.
   */
  public function alterOutputs(BlueprintAdditionsInterface $outputs): void;

}
