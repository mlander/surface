<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('accent', Schema::string()
      ->title(t('Accent'))
      ->default('#336699')
      ->annotation('x-surface-settings', ['format' => 'color']));
    $inputs->add('trim', Schema::options(['#000000' => t('Black'), '#ffffff' => t('White')])
      ->title(t('Trim'))
      ->default('#000000')
      ->annotation('x-surface-settings', ['format' => 'color'])
      ->ui(widget: 'radios'));
    $inputs->add('caption', Schema::string()
      ->title(t('Caption')));
  }

}
