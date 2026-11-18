<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The test formatter's settings: a prefix, a casing, and its variant.
 *
 * Named by SurfaceTestFormatter with #[UsesStructure]; the variants are
 * the test block's, so the two hosts can be compared key for key.
 */
#[Structure('test.formatter')]
final class TestFormatterStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('prefix', Schema::string()
      ->title(t('Prefix'))
      ->description(t('Text placed before each value.'))
      ->maxLength(10));
    $inputs->add('casing', Schema::options([
      'none' => t('As written'),
      'uppercase' => t('Upper case'),
      'lowercase' => t('Lower case'),
    ])
      ->title(t('Casing'))
      ->default('none'));
    $inputs->add('variant', Schema::string()
      ->title(t('Variant'))
      ->description(t('Pick a casing other than none to see its variants.')));
  }

  /**
   * A casing other than none offers its own variants, and no others.
   */
  #[RefinesInput('variant')]
  public static function variantOfCasing(string $casing): Schema {
    $offered = TestBlockStructure::variants()[$casing] ?? NULL;
    return $offered === NULL ? Schema::any() : Schema::options($offered);
  }

}
