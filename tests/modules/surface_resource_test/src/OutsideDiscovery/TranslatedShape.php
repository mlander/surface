<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says its pattern's message with t(), read in the language it is said in.
 */
#[Shape('resource_test.translated')]
final class TranslatedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Lowercase code'))
      ->pattern('^[a-z]+$')
      ->message('pattern', t('Lowercase letters only.'));
  }

}
