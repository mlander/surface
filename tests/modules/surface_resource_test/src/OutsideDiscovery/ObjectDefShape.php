<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Refers to an object def, which the blueprint it is read in refuses.
 */
#[Document('resource_test.broken.object_def')]
final class ObjectDefShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::of('#/$defs/box')->def('box', Schema::object(['label' => Schema::string()]));
  }

}
