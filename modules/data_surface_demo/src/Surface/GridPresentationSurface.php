<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * What the demo block needs to know when it renders a grid.
 *
 * The other child of the block's presentation slot.
 *
 * @see \Drupal\data_surface_demo\Surface\DemoBlockSurface
 */
#[Surface('block.data_surface_demo.presentation.grid')]
final class GridPresentationSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('columns', 'integer', new TranslatableMarkup('Columns'), default: 3)
      ->setDescription(new TranslatableMarkup('How many items sit side by side.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 6]);
  }

}
