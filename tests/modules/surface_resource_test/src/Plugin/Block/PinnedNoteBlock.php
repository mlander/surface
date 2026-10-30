<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_resource_test\Resource\PinnedNoteResource;

/**
 * A block whose configuration is a resource that names its storage too.
 */
#[Block(
  id: 'surface_resource_test_pinned_note',
  admin_label: new TranslatableMarkup('Pinned note (resource test)'),
)]
#[UsesStructure(PinnedNoteResource::class)]
final class PinnedNoteBlock extends TypedBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}
