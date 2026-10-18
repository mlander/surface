<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

/**
 * A refiner link that can say, before running, where it may rule out.
 *
 * A #[RefinesInput] method that may answer DoesNotApply says so in its
 * return type, and the link it is bound in reports it here, so what is
 * stated ahead of time (the full schema, the tool's listing) can leave a
 * key it may rule out out of `required` without running anything.
 *
 * Decision: see docs/decisions.md#a-key-that-does-not-apply.
 *
 * @internal
 */
interface ApplicabilityInterface {

  /**
   * Lists where this link may answer that a key does not apply.
   *
   * @param string $name
   *   The key it is registered on.
   *
   * @return string[]
   *   '' for the key itself, or a property's dotted path below it (a key
   *   an alter mounted, inside the mount); empty when it never does.
   */
  public function mayNotApply(string $name): array;

}
