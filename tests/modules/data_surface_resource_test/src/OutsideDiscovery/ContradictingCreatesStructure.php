<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: a context that creates, for an operation that says it does not.
 *
 * Refused at discovery, before anything asks for it.
 */
#[Structure('resource_test.broken.contradicting_creates')]
final class ContradictingCreatesStructure implements StructureInterface {

  /**
   * Creates, though its attribute never says so.
   */
  #[Operation('fill', label: 'Fill a jar')]
  public static function fill(): OperationContext {
    return new OperationContext(creates: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
