<?php

declare(strict_types=1);

namespace Drupal\surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_test\Structure\TestBlockStructure;

/**
 * A block that adopts structures and says nothing else about its settings.
 *
 * #[UsesStructure] and build(). No defaultConfiguration, no blockForm, no
 * blockValidate, no blockSubmit.
 */
#[Block(
  id: 'surface_test_block',
  admin_label: new TranslatableMarkup('Data surface test block'),
  forms: ['alternate' => 'surface_test.alternate_form'],
)]
#[UsesStructure(TestBlockStructure::class)]
final class SurfaceTestBlock extends TypedBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $items = [];
    foreach ($this->getSurface()->getDefinitions() as $name => $definition) {
      $items[] = $definition->getLabel() . ': ' . var_export($configuration[$name] ?? NULL, TRUE);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $configuration['headline'] ?? '',
      '#items' => $items,
    ];
  }

}
