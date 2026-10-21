<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * The deprecated with(), kept one release beside mixIn().
 *
 * @internal
 */
trait DeprecatedWithTrait {

  /**
   * {@inheritdoc}
   */
  public function with(string $structure, array $reads = []): static {
    // No drupal.org issue tracks the rename yet: the project page stands in.
    // phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorSeeUrlFormat
    @trigger_error('BlueprintAdditionsInterface::with() is deprecated in data_surface:1.0.0 and is removed from data_surface:2.0.0. Use ::mixIn() instead. See https://www.drupal.org/project/data_surface', E_USER_DEPRECATED);
    return $this->mixIn($structure, $reads);
  }

}
