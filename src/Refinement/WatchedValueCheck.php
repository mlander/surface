<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Validation\ConstraintRunner;

/**
 * The module's watched value check: the definition's own constraints.
 *
 * The same question the pipeline asks of a value it validates, through
 * the same typed data (ConstraintRunner::conforms()), so a value the
 * pipeline refuses is a value no refiner is handed. Only the yes or no
 * is kept; what the refusal says is the pipeline's to report, under the
 * key that holds the value.
 */
final class WatchedValueCheck implements WatchedValueCheckInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a WatchedValueCheck.
   *
   * @param \Drupal\surface\Validation\ConstraintRunner $constraintRunner
   *   The constraint runner, which runs the constraints.
   */
  public function __construct(
    protected ConstraintRunner $constraintRunner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function admits(DataDefinitionInterface $definition, mixed $value): bool {
    try {
      return $this->constraintRunner->conforms($definition, $value)->count() === 0;
    }
    catch (\InvalidArgumentException) {
      // Typed data refuses a value it cannot hold at all, a string for a
      // map, before any constraint runs: as refused as a refusal gets.
      return FALSE;
    }
  }

}
