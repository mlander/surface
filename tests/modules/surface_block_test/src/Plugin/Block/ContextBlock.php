<?php

declare(strict_types=1);

namespace Drupal\surface_block_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block that reads the current user: context-aware, so not offered yet.
 */
#[Block(
  id: 'surface_block_test_context',
  admin_label: new TranslatableMarkup('Reads a user'),
  context_definitions: [
    'user' => new EntityContextDefinition('entity:user', new TranslatableMarkup('User')),
  ],
)]
final class ContextBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [];
  }

}
