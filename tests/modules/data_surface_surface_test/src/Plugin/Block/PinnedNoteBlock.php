<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_surface_test\Surface\PinnedNoteSurface;

/**
 * A block whose configuration is a surface that names a target too.
 */
#[Block(
  id: 'data_surface_surface_test_pinned_note',
  admin_label: new TranslatableMarkup('Pinned note (surface test)'),
)]
#[UsesSurface(PinnedNoteSurface::class)]
final class PinnedNoteBlock extends DataSurfaceBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}
