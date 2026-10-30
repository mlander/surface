<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * An alter that adds inputs to a resource or structure it does not own.
 *
 * What makes a class an alter is #[Alters] on it. This interface
 * is for one that adds inputs; #[RefinesInput] methods for one that
 * tightens; OperationAccessInterface for one that answers access. A class
 * may do any of the three. Never change or remove what the owner declared,
 * and add no outputs: what a call answers is its operation's.
 *
 * What an alter adds is mounted under the alter's own module name, at
 * third_party_settings.<module>.<key>, which is where the engine keeps
 * every contribution so that the owner's storage and schema never have to
 * know a contributor's keys.
 */
interface AltersInputsInterface {

  /**
   * Adds inputs to the resource or structure named on #[Alters].
   *
   * @param \Drupal\surface\Resource\BlueprintAdditionsInterface $inputs
   *   The input blueprint, add-only.
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void;

}
