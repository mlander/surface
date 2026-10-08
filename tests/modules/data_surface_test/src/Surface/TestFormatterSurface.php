<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The test formatter's settings: a prefix, a casing, and its variant.
 *
 * Named by DataSurfaceTestFormatter with #[UsesSurface]; the variants are
 * the test block's, so the two hosts can be compared key for key.
 */
#[Surface('test.formatter')]
final class TestFormatterSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('prefix', 'string', new TranslatableMarkup('Prefix'))
      ->setDescription(new TranslatableMarkup('Text placed before each value.'))
      ->addConstraint('Length', ['max' => 10]);
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
    $offered = TestBlockSurface::variants()[$casing] ?? NULL;
    return $offered === NULL
      ? $variant
      : $variant->addConstraint('LabeledChoice', ['choices' => $offered]);
  }

}
