<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Plugin\Block\StickyNoteBlock;

/**
 * Alters a plugin that is its own surface, naming the plugin class.
 */
#[AltersSurface(StickyNoteBlock::class)]
final class StickyNoteAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->describe('note', description: 'Written on the note.');
  }

}
