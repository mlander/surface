<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Booth;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A booth whose width and depth both follow its size.
 *
 * Two keys watching one sibling, so the full schema enumerates both over
 * the same three sizes: each combination is refined once for the two of
 * them (Conditionals::refinedFor()).
 */
#[Structure('resource_test.booth')]
final class BoothStructure implements StructureInterface {

  /**
   * The most each size allows, in meters.
   */
  protected const MAX = ['small' => 2, 'medium' => 4, 'large' => 8];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('size', Schema::string()
      ->title(t('Size'))
      ->check('drupal:Choice', ['choices' => array_keys(self::MAX)]));
    $inputs->addSchema('width', Schema::integer()
      ->title(t('Width'))
      ->minimum(1)
      ->maximum(8));
    $inputs->addSchema('depth', Schema::integer()
      ->title(t('Depth'))
      ->minimum(1)
      ->maximum(8));
  }

  /**
   * The widest a booth of the size may be.
   */
  #[RefinesInput('width')]
  public static function widthForSize(?string $size): Schema {
    return static::upTo($size);
  }

  /**
   * The deepest a booth of the size may be.
   */
  #[RefinesInput('depth')]
  public static function depthForSize(?string $size): Schema {
    return static::upTo($size);
  }

  /**
   * Narrows a measure to what the size allows.
   *
   * @param string|null $size
   *   The size, or NULL when none is chosen.
   *
   * @return \PortableSchema\Builder\Schema
   *   What the size adds to the key.
   */
  protected static function upTo(?string $size): Schema {
    if ($size === NULL || !isset(self::MAX[$size])) {
      return Schema::any();
    }
    return Schema::any()->minimum(1)->maximum(self::MAX[$size]);
  }

}
