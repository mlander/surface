<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Three field types' storage settings, straight from config schema.
 */
#[Structure('reader_test.storage_direct')]
final class FieldStorageDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('type', Schema::enum(['decimal', 'entity_reference', 'string'])
      ->title(t('Plugin')));

    $inputs->attachVariants('settings', by: 'type', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'type' => 'field.storage.*.*',
      'path' => 'settings',
      'by' => 'type',
    ]);
  }

}
