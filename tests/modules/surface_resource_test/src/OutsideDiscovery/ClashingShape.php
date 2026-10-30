<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

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
