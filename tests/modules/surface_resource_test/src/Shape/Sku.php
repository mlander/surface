<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Shape;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A stock code: two capitals and digits, used at several keys.
 *
 * The depot uses it at two keys that narrow it differently, and the shelf
 * tag reads it through a port; SkuAlter tightens it everywhere.
 */
#[Shape('resource_test.sku')]
final class Sku implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Stock code'))
      ->description(t('Letters and digits.'))
      ->pattern('^[A-Z]{2}\d+$')
      ->message('pattern', 'A stock code is two capitals and digits, such as AB12.')
      ->examples(['AB12']);
  }

}
