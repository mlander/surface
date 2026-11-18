<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface_reader_test\Variants\BlockPluginJsonStructures;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Three block plugins' settings, through the JSON Schema route.
 */
#[Structure('reader_test.block_json')]
final class BlockJsonStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('plugin', Schema::enum(['page_title_block', 'system_branding_block', 'system_menu_block:main'])
      ->title(t('Plugin')));

    $inputs->attachVariants('settings', by: 'plugin', label: t('Settings'), from: BlockPluginJsonStructures::class);
  }

}
