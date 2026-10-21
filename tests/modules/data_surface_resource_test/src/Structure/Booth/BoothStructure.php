<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Booth;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A booth whose width and depth both follow its size.
 *
 * Two keys watching one sibling, so the full schema enumerates both over
 * the same three sizes: each combination is refined once for the two of
 * them (ContractEmitter::refinedFor()).
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
    $inputs->add('size', 'string', t('Size'))
      ->addConstraint('Choice', ['choices' => array_keys(self::MAX)]);
    $inputs->add('width', 'integer', t('Width'))
      ->addConstraint('Range', ['min' => 1, 'max' => 8]);
    $inputs->add('depth', 'integer', t('Depth'))
      ->addConstraint('Range', ['min' => 1, 'max' => 8]);
  }

  /**
   * The widest a booth of the size may be.
   */
  #[RefinesInput('width')]
  public static function widthForSize(DataDefinitionInterface $width, ?string $size): DataDefinitionInterface {
    return static::upTo($width, $size);
  }

  /**
   * The deepest a booth of the size may be.
   */
  #[RefinesInput('depth')]
  public static function depthForSize(DataDefinitionInterface $depth, ?string $size): DataDefinitionInterface {
    return static::upTo($depth, $size);
  }

  /**
   * Narrows a measure to what the size allows.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $measure
   *   The key's definition.
   * @param string|null $size
   *   The size, or NULL when none is chosen.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The key, narrowed.
   */
  protected static function upTo(DataDefinitionInterface $measure, ?string $size): DataDefinitionInterface {
    if ($size === NULL || !isset(self::MAX[$size]) || !$measure instanceof DataDefinition) {
      return $measure;
    }
    return $measure->addConstraint('Range', ['min' => 1, 'max' => self::MAX[$size]]);
  }

}
