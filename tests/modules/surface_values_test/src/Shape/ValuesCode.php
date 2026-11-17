<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Shape;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A code of capitals, used by a key the value baseline casts.
 */
#[Document('values_test.code')]
final class ValuesCode implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Code'))
      ->pattern('^[A-Z]+$');
  }

}
