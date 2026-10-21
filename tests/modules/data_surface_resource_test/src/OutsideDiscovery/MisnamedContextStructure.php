<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: an operation that needs nothing returns a context under another id.
 *
 * Refused at discovery, before anything asks for it.
 */
#[Structure('resource_test.broken.misnamed_context')]
final class MisnamedContextStructure implements StructureInterface {

  /**
   * Says it is the pour operation.
   */
  #[Operation('fill', label: 'Fill a jar')]
  public static function fill(): OperationContext {
    return new OperationContext('pour');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
