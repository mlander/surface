<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * What the demo block needs to know when it renders a grid.
 *
 * The other child of the block's presentation slot.
 *
 * @see \Drupal\data_surface_demo\Structure\DemoBlockStructure
 */
#[Structure('block.data_surface_demo.presentation.grid')]
#[Variant(of: DemoBlockStructure::class, key: 'presentation_settings', value: 'grid')]
final class GridPresentationStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('columns', 'integer', t('Columns'), default: 3)
      ->setDescription(t('How many items sit side by side.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 6]);
  }

}
