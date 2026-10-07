<?php

declare(strict_types=1);

namespace Drupal\demo\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\demo\Surface\TeaserBlockSurface;
use Drupal\surface_sketch\Surface\Attribute\UsesSurface;

/**
 * The plugin keeps its job: rendering. Its configuration is a surface.
 *
 * No blockForm(), blockValidate() or blockSubmit(). The block host reads
 * #[UsesSurface] from the definition, builds the surface in the
 * `configure` situation with the instance's configuration loaded, and
 * writes accepted values back to it. Same for a formatter, a condition,
 * an action: the host differs, the surface does not.
 */
#[Block(id: 'teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesSurface(TeaserBlockSurface::class)]
final class TeaserBlock extends BlockBase {

  public function build(): array {
    return [
      '#theme' => 'teaser',
      '#headline' => $this->configuration['headline'],
      '#entity_type' => $this->configuration['entity_type'],
      '#bundle' => $this->configuration['bundle'],
      '#limit' => $this->configuration['limit'],
    ];
  }

}
