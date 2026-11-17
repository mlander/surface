<?php

declare(strict_types=1);

namespace Drupal\surface_examples_block\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;
use Drupal\surface_examples_block\Variants\ConditionPluginStructures;
use PortableSchema\Builder\Schema;

/**
 * One condition a placed block is shown under.
 *
 * The condition plugin, whether its answer is negated, and its settings:
 * the variant the plugin chooses, its declared structure or what its
 * config schema says, with the contexts it reads where it reads any
 * (ConditionPluginStructures). The settings are stored flat beside the
 * id and the negation, as core keeps a condition's configuration
 * (Variants::STORED_AS), and asked for under `settings`. A block keeps
 * its conditions keyed by the plugin, so a placement holds each at most
 * once; its storage refuses a second.
 */
#[Structure('block.placement.condition')]
final class VisibilityConditionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('id', Schema::string()
      ->title(t('Condition'))
      ->description(t('The condition plugin.'))
      ->required());

    $inputs->addSchema('negate', Schema::bool()
      ->title(t('Negate'))
      ->description(t('Whether the block is shown when the condition does not hold, rather than when it does.'))
      ->default(FALSE));

    $inputs->attachVariants('settings', by: 'id', label: t('Settings'), from: ConditionPluginStructures::class)
      ->setDescription(t('The settings of the condition chosen.'))
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
