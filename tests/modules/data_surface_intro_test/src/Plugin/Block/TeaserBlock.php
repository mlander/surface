<?php

declare(strict_types=1);

namespace Drupal\data_surface_intro_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_intro_test\Structure\TeaserBlockStructure;

/**
 * The teaser block, as docs/introduction/09-structures.md shows it.
 *
 * Its settings are TeaserBlockStructure's; the page leaves build() out as
 * unchanged, the one method a block keeps when it adopts the module.
 */
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesStructure(TeaserBlockStructure::class)]
final class TeaserBlock extends DataSurfaceBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['headline'] ?? '')];
  }

}
