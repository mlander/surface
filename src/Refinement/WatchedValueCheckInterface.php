<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Answers whether a watched value may be handed to a refiner.
 *
 * A refiner narrows one key by what a sibling holds, and a sibling
 * holding a value its own definition refuses has not said anything a
 * refiner can trust: an event license typed in the wrong format is no
 * license. The engine asks this before it hands a watched value over,
 * against the sibling's own refined definition, and passes a refused
 * value as if the sibling held nothing.
 *
 * It rides inside a sealed data surface, which is otherwise pure data, so an
 * implementation must serialize without its container: hold services
 * through the dependency serialization trait, as a refiner link does.
 *
 * @see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling
 *
 * @internal
 *   The build step seals every data surface with the module's own check.
 */
interface WatchedValueCheckInterface {

  /**
   * Answers whether a value satisfies the definition it is held against.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The watched key's definition, refined against the values a refiner
   *   is being handed with it.
   * @param mixed $value
   *   What the key holds, configured.
   *
   * @return bool
   *   TRUE when the definition's constraints accept the value.
   */
  public function admits(DataDefinitionInterface $definition, mixed $value): bool;

}
