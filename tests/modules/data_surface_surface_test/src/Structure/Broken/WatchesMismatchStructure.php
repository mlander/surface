<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a watches list that does not match its parameters.
 */
#[Structure('surface_test.broken.watches_mismatch')]
final class WatchesMismatchStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('first', 'string', 'First');
    $inputs->add('second', 'string', 'Second');
    $inputs->add('third', 'string', 'Third');
  }

  /**
   * Lists watches its parameters no longer match.
   */
  #[RefinesInput('third', watches: ['first', 'second'])]
  public static function thirdOf(DataDefinitionInterface $third, string $first, string $renamed): DataDefinitionInterface {
    return $third;
  }

}
