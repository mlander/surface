<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A contribution refiner: narrows the one value this module contributed.
 *
 * It is handed its own contribution and nothing else, so the only thing
 * it can say is whether that value is still on offer. Here it is, unless
 * the text is not cased at all.
 */
final class RibbonVariantRefiner implements DataSurfaceRefinerInterface {

  /**
   * The choice this module contributes.
   */
  public const CHOICE = ['ribbon'];

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if (($values['casing'] ?? NULL) !== 'none') {
      return $definition;
    }
    $definition->addConstraint('Choice', ['choices' => []]);
    return $definition;
  }

}
