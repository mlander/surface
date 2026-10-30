<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Larder\SpiceRackResource;

/**
 * Broken: names an operation its child does not declare.
 */
#[Structure('resource_test.broken.misnaming_larder')]
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
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('rack', SpiceRackResource::class);
  }

}
