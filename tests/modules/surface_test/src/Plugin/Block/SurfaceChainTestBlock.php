<?php

declare(strict_types=1);

namespace Drupal\surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_test\Structure\ChainBlockStructure;

/**
 * Three keys, each narrowing the next: the discard rule's fixture.
 *
 * @see \Drupal\surface_test\Structure\ChainBlockStructure
 * @see \Drupal\Tests\surface\Kernel\RefinementDiscardTest
 */
#[Block(
  id: 'surface_chain_test_block',
  admin_label: new TranslatableMarkup('Data surface chain test block'),
)]
#[UsesStructure(ChainBlockStructure::class)]
final class SurfaceChainTestBlock extends TypedBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#markup' => 'chain'];
  }

}
