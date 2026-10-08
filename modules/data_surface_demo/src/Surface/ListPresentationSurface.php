<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * What the demo block needs to know when it renders a list.
 *
 * One of the two variants of the block's presentation slot, filling it
 * for `list` with #[SurfaceVariant]; the grid is the other. Nothing here
 * depends on anything, and nothing here can see the block's own keys.
 *
 * @see \Drupal\data_surface_demo\Surface\DemoBlockSurface
 */
#[Surface('block.data_surface_demo.presentation.list')]
#[SurfaceVariant(of: DemoBlockSurface::class, key: 'presentation_settings', value: 'list')]
final class ListPresentationSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('show_summary', 'boolean', new TranslatableMarkup('Show summaries'), default: TRUE)
      ->setDescription(new TranslatableMarkup('Whether item summaries render.'));
  }

}
