<?php

declare(strict_types=1);

namespace Drupal\data_surface_component_block\Resource;

use Drupal\block\BlockInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_component_block\Plugin\Block\ComponentBlock;
use Drupal\data_surface_component_block\Storage\PlacedComponentBlockStorage;
use Drupal\data_surface_component_block\Structure\ComponentBlockStructure;

/**
 * A placed component block: its settings, by the block's machine name.
 *
 * The block's structure is the plugin's, which no tool configures: a
 * plugin's host holds it. A placed block is a config entity, with identity
 * and storage of its own, so this resource names one and attaches the
 * structure at `settings`, where the block keeps it, and the Tool API
 * bridge derives a write tool and a read tool from it.
 */
#[Resource('block.placed_component',
  identity: ['block'],
  storage: PlacedComponentBlockStorage::class,
)]
final class PlacedComponentBlockResource implements ResourceInterface {

  /**
   * Configures a placed component block, named by its machine name.
   *
   * @throws \InvalidArgumentException
   *   When the block named is not a component block.
   */
  #[Operation('configure',
    label: new TranslatableMarkup('Configure a placed component block'),
    description: new TranslatableMarkup('Sets the component a placed block embeds, the block named by its machine name, and the props the component is drawn with.'),
    permission: 'administer blocks',
  )]
  public static function configure(BlockInterface $block): OperationContext {
    if ($block->getPluginId() !== ComponentBlock::ID) {
      throw new \InvalidArgumentException(sprintf('The block "%s" is a %s block, not a component block.', $block->id(), $block->getPluginId()));
    }
    return new OperationContext(known: ['block' => (string) $block->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('block', 'string', t('Block'))
      ->setDescription(t('The machine name of the placed block.'))
      ->setRequired(TRUE);
    $inputs->attach('settings', ComponentBlockStructure::class)
      ->setLabel(t('Settings'));
  }

}
