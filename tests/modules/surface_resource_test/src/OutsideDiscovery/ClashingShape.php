<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Claims the ledger's id, which names the ledger's def already.
 */
#[Document('resource_test.ledger')]
final class ClashingShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string();
  }

}
