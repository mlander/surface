<?php

declare(strict_types=1);

namespace Drupal\data_surface_widget_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Three keys: two colors and a caption.
 *
 * The accent is a string whose format is color, which the color picker
 * claims. The trim is one too, and its owner asks for radios, which win
 * over the claim. The caption is a plain string, which the stock rule
 * draws.
 */
#[Structure('widget_test.swatch')]
final class SwatchStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('accent', 'string', t('Accent'), default: '#336699')
      ->setSetting('format', 'color');
    $inputs->add('trim', 'string', t('Trim'), default: '#000000')
      ->setSetting('format', 'color')
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', ['choices' => ['#000000' => t('Black'), '#ffffff' => t('White')]]);
    $inputs->add('caption', 'string', t('Caption'));
  }

}
