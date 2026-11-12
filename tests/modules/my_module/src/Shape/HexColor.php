<?php

declare(strict_types=1);

namespace Drupal\my_module\Shape;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A color as # and six hexadecimal digits, declared once.
 *
 * The shape docs/introduction/04-shapes.md shows in full. MyColorsResource
 * uses it at two keys; IntroductionExcerptsTest finds the def the served
 * contract writes for it on the page.
 */
#[Shape('my_module.hex_color')]
final class HexColor implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Color'))
      ->description(t('A # and six hexadecimal digits, such as #0969DA.'))
      ->pattern('^#[0-9A-Fa-f]{6}$')
      ->message('pattern', t('Write the color as # and six hexadecimal digits, such as #0969DA.'))
      ->examples(['#0969DA']);
  }

}
