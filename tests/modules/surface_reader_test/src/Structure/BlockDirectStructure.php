<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Three block plugins' settings, straight from config schema.
 */
#[Structure('reader_test.block_direct')]
final class BlockDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('plugin', Schema::enum(['page_title_block', 'system_branding_block', 'system_menu_block:main'])
      ->title(t('Plugin')));

    $inputs->attachVariants('settings', by: 'plugin', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'type' => 'block.block.*',
      'path' => 'settings',
      'by' => 'plugin',
    ]);
  }

}
