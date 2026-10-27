<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_block\Variants\ConditionPluginStructures;

/**
 * One condition a placed block is shown under.
 *
 * The condition plugin, whether its answer is negated, and its settings:
 * the variant the plugin chooses, its declared structure or what its
 * config schema says, with the contexts it reads where it reads any
 * (ConditionPluginStructures). A block keeps its conditions keyed by the
 * plugin, so a placement holds each at most once; its storage refuses a
 * second.
 */
#[Structure('block.placement.condition')]
final class VisibilityConditionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Condition'))
      ->setDescription(t('The condition plugin.'))
      ->setRequired(TRUE);

    $inputs->add('negate', 'boolean', t('Negate'), default: FALSE)
      ->setDescription(t('Whether the block is shown when the condition does not hold, rather than when it does.'));

    $inputs->attachVariants('settings', by: 'id', from: ConditionPluginStructures::class)
      ->setLabel(t('Settings'))
      ->setDescription(t('The settings of the condition chosen.'));
  }

}
