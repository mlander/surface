<?php

declare(strict_types=1);

namespace Drupal\my_module\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

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
  public static function define(): DataDefinition {
    $color = DataDefinition::create('string')
      ->setLabel(t('Color'))
      ->setDescription(t('A # and six hexadecimal digits, such as #0969DA.'))
      ->addConstraint('Regex', [
        'pattern' => '/^#[0-9A-Fa-f]{6}$/D',
        'message' => t('Write the color as # and six hexadecimal digits, such as #0969DA.'),
      ]);
    DefinitionMetadata::setExamples($color, ['#0969DA']);
    return $color;
  }

}
