<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three field types' storage settings, straight from config schema.
 */
#[Structure('reader_test.storage_direct')]
final class FieldStorageDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('type', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['decimal', 'entity_reference', 'string']]);

    $inputs->attachVariants('settings', by: 'type', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'field.storage.*.*',
      'settings',
      'type',
    ]);
  }

}
