<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_surface_test\Resource\Larder\SpiceRackResource;

/**
 * Broken: names an operation its child does not declare.
 */
#[Structure('surface_test.broken.misnaming_larder')]
final class MisnamingLarderStructure implements StructureInterface {

  /**
   * Names the rack's "dust" operation, which there is none of.
   */
  #[Operation('clean', label: 'Clean')]
  public static function clean(): OperationContext {
    return (new OperationContext('clean'))->withChild('rack', operation: 'dust');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->attach('rack', SpiceRackResource::class);
  }

}
