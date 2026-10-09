<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;

/**
 * The module's watched value check: the definition's own constraints.
 *
 * The same question the pipeline asks of a value it validates, through
 * the same typed data, so a value the pipeline refuses is a value no
 * refiner is handed. Only the yes or no is kept; what the refusal says
 * is the pipeline's to report, under the key that holds the value.
 */
final class WatchedValueCheck implements WatchedValueCheckInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a WatchedValueCheck.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which runs the constraints.
   */
  public function __construct(
    protected TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function admits(DataDefinitionInterface $definition, mixed $value): bool {
    try {
      return $this->typedDataManager->create($definition, $value)->validate()->count() === 0;
    }
    catch (\InvalidArgumentException) {
      // Typed data refuses a value it cannot hold at all, a string for a
      // map, before any constraint runs: as refused as a refusal gets.
      return FALSE;
    }
  }

}
