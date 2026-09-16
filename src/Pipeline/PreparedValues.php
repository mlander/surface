<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Everything a storage write needs, before anything is written.
 *
 * The preview artifact of the pipeline: a target turns accepted values
 * into its own storage shape and hands back this object, so a caller can
 * render, diff, or discard the result of a submission without the write
 * having happened. Commit takes the same object and stores it.
 */
final class PreparedValues {

  /**
   * Constructs a PreparedValues object.
   *
   * @param array $values
   *   The accepted, validated values in surface shape.
   * @param mixed $artifact
   *   The storage shape the target produced: a configuration array, a
   *   config object, an unsaved entity, whatever the target commits.
   * @param array $dependencies
   *   Dependencies the artifact carries, in the shape
   *   calculateDependencies() collects them; empty when the target has
   *   nothing to declare.
   */
  public function __construct(
    public readonly array $values,
    public readonly mixed $artifact,
    public readonly array $dependencies = [],
  ) {
  }

}
