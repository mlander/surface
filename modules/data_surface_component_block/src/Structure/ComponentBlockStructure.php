<?php

declare(strict_types=1);

namespace Drupal\data_surface_component_block\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface\Variants\ComponentProps;

/**
 * What the component block is configured with: a component, and its props.
 *
 * The same two keys as example 12's spotlight, from the same provider:
 * each single-directory component the site has is one variant at `props`,
 * read from the props schema it declares. The block host supplies the
 * context and the storage, the block's settings.
 *
 * The component is not required, where example 12's is: the host builds
 * the block from the declared defaults before anything is chosen, and no
 * component is one every site has, so none is the default and a block with
 * none renders nothing.
 * Decision: see
 * docs/decisions.md#a-block-embeds-a-component-through-its-structure.
 *
 * @see \Drupal\data_surface_component_block\Plugin\Block\ComponentBlock
 */
#[Structure('block.data_surface_component')]
final class ComponentBlockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('component', 'string', t('Component'));
    $inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
      ->setLabel(t('Props'));
  }

}
