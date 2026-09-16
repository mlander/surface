<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Narrows output definitions against the accepted input values.
 *
 * The output half of DataSurfaceRefinerInterface, and a separate
 * interface rather than a second method on that one for a reason worth
 * stating: every host base class in this module already implements the
 * input refiner, so adding a method there would make every one of them,
 * and every refiner anybody else has written, carry an empty method for
 * a feature it does not use. A host that refines its outputs says so by
 * implementing this as well, and a host that does not stays exactly as
 * thin as it was.
 *
 * What an output refines against is the *input*: a formatter whose
 * emitted classes depend on the chosen variant, a tool whose returned
 * map has a property only when a flag was sent. There is no such thing
 * as an output refining against another output, because outputs are
 * produced in one act by code that already knows all of them.
 *
 * The narrowing contract is the same one, checked the same way by
 * Refinement\Narrowing: a refined output definition must accept only
 * values the definition it was handed already accepted. That is what
 * lets a consumer read the advertised output schema once and never be
 * surprised by what it is handed.
 *
 * A refiner whose answer depends on site state says so by also
 * implementing core's CacheableDependencyInterface, exactly as an input
 * refiner does; the metadata is merged into the refined surface.
 *
 * @see \Drupal\data_surface\DataSurfaceRefinerInterface
 * @see \Drupal\data_surface\DataSurfaceInterface::refineOutputs()
 * @see docs/outputs.md
 */
interface DataSurfaceOutputRefinerInterface {

  /**
   * Refines one output definition against the input values.
   *
   * @param string $name
   *   The output key being refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to refine. Always a deep clone — implementations
   *   may mutate and return it, or return a replacement.
   * @param array $input_values
   *   The accepted input values, keyed by surface key. Every dependency
   *   the output declares is present and configured.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The refined definition.
   */
  public function refineOutputDefinition(string $name, DataDefinitionInterface $definition, array $input_values): DataDefinitionInterface;

}
