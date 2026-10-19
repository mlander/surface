<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * Claims the ledger's id, which names the ledger's def already.
 */
#[Shape('resource_test.ledger')]
final class ClashingShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string');
  }

}
