<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

/**
 * Everything a storage write needs, before anything is written.
 *
 * The preview artifact of the pipeline: a storage turns accepted values
 * into its own storage layout and hands back this object, so a caller can
 * render, diff, or discard the result of a submission without the write
 * having happened. Commit takes the same object and stores it.
 */
final class PreparedValues {

  /**
   * Constructs a PreparedValues object.
   *
   * @param array $values
   *   The accepted, validated values in input layout.
   * @param mixed $artifact
   *   What the storage produced, in its own layout: a configuration
   *   array, a config object, an unsaved entity, whatever it commits.
   * @param array $dependencies
   *   Dependencies the artifact carries, in the shape
   *   calculateDependencies() collects them; empty when the storage has
   *   nothing to declare.
   * @param bool $changesNothing
   *   TRUE when prepared for an operation declared read only, which the
   *   pipeline's commit() refuses.
   */
  public function __construct(
    public readonly array $values,
    public readonly mixed $artifact,
    public readonly array $dependencies = [],
    public readonly bool $changesNothing = FALSE,
  ) {
  }

  /**
   * Returns the same values, prepared for an operation changing nothing.
   *
   * @return self
   *   The values, which the pipeline's commit() refuses.
   */
  public function changingNothing(): self {
    return new self($this->values, $this->artifact, $this->dependencies, TRUE);
  }

}
