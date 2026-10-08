<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The test block's configuration: five keys, one refined by another.
 *
 * Named by DataSurfaceTestBlock with #[UsesSurface]. The variant a
 * casing offers is the one dependent key, so the block host's refinement
 * rebuild, the plugin form and the pipeline all have something to
 * narrow.
 */
#[Surface('test.block')]
final class TestBlockSurface implements SurfaceInterface {

  /**
   * The variant choices each casing offers.
   *
   * A method rather than a class constant because the labels are
   * translatable markup, and PHP allows no object in a constant.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Variant labels keyed by value, keyed by the casing offering them.
   */
  public static function variants(): array {
    return [
      'uppercase' => [
        'bold' => new TranslatableMarkup('Bold'),
        'strong' => new TranslatableMarkup('Strong'),
      ],
      'lowercase' => [
        'quiet' => new TranslatableMarkup('Quiet'),
        'muted' => new TranslatableMarkup('Muted'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $headline = $inputs->add('headline', 'string', new TranslatableMarkup('Headline'), default: 'Featured')
      ->setDescription(new TranslatableMarkup('Shown above the items.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 20]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $inputs->add('limit', 'integer', new TranslatableMarkup('Number of items'), default: 10)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
    $inputs->add('show_summary', 'boolean', new TranslatableMarkup('Show summaries'), default: TRUE);
    $inputs->add('casing', 'string', new TranslatableMarkup('Casing'), default: 'none')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'none' => new TranslatableMarkup('As written'),
          'uppercase' => new TranslatableMarkup('Upper case'),
          'lowercase' => new TranslatableMarkup('Lower case'),
        ],
      ]);
    $inputs->add('variant', 'string', new TranslatableMarkup('Variant'))
      ->setDescription(new TranslatableMarkup('Pick a casing other than none to see its variants.'));
  }

  /**
   * A casing other than none offers its own variants, and no others.
   */
  #[RefinesInput('variant')]
  public function variantOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    $offered = static::variants()[$casing] ?? NULL;
    if ($offered === NULL) {
      return $variant;
    }
    $variant->addConstraint('LabeledChoice', ['choices' => $offered]);
    if ($variant instanceof DataDefinition) {
      $variant->setDescription(new TranslatableMarkup('A @casing variant.', ['@casing' => $casing]));
    }
    return $variant;
  }

}
