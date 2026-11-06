<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three block plugins' settings, straight from config schema.
 */
#[Structure('reader_test.block_direct')]
final class BlockDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('plugin', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['page_title_block', 'system_branding_block', 'system_menu_block:main']]);

    $inputs->attachVariants('settings', by: 'plugin', from: ConfigSchemaTypes::class, arguments: [
      'block.block.*',
      'settings',
      'plugin',
    ])
      ->setLabel(t('Settings'));
  }

}
