<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_surface_test\Resource\PinnedNoteResource;

/**
 * A block whose configuration is a resource that names its storage too.
 */
#[Block(
  id: 'data_surface_surface_test_pinned_note',
  admin_label: new TranslatableMarkup('Pinned note (surface test)'),
)]
#[UsesStructure(PinnedNoteResource::class)]
final class PinnedNoteBlock extends DataSurfaceBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}
