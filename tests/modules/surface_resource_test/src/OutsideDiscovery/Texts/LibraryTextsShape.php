<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Texts;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A type of the library, with a title and a pattern's message of its own.
 */
#[Shape('resource_test.texts.library')]
final class LibraryTextsShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::of('portable:email')
      ->title(TextsMade::t('Email'))
      ->pattern('@example\.com$')
      ->message('pattern', TextsMade::t('Use an example.com address.'));
  }

}
