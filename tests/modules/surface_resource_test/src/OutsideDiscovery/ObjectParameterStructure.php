<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\Entity\EntityInterface;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StructureInterface;

/**
 * Broken: an operation handed an object, where a value names its subject.
 *
 * Refused at discovery, naming the scalar to type it as.
 */
#[Structure('resource_test.broken.object_parameter')]
final class ObjectParameterStructure implements StructureInterface {

  /**
   * Takes an entity, which a door would have to load beside the storage.
   */
  #[Operation('fill', label: 'Fill a jar')]
  public static function fill(EntityInterface $jar): OperationContext {
    return new OperationContext(known: ['jar' => $jar->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jar', 'string', 'Jar');
  }

}
