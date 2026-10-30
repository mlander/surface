<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StructureInterface;

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
