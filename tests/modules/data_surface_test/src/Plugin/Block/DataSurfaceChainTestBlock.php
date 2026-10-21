<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_test\Structure\ChainBlockStructure;

/**
 * Three keys, each narrowing the next: the discard rule's fixture.
 *
 * @see \Drupal\data_surface_test\Structure\ChainBlockStructure
 * @see \Drupal\Tests\data_surface\Kernel\RefinementDiscardTest
 */
#[Block(
  id: 'data_surface_chain_test_block',
  admin_label: new TranslatableMarkup('Data surface chain test block'),
)]
#[UsesStructure(ChainBlockStructure::class)]
final class DataSurfaceChainTestBlock extends DataSurfaceBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#markup' => 'chain'];
  }

}
