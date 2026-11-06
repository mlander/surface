<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface_examples_field\FieldStorageSettingsSchemaVariants;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three field types' storage settings, through the JSON Schema route.
 */
#[Structure('reader_test.storage_json')]
final class FieldStorageJsonStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('field_type', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['decimal', 'entity_reference', 'string']]);

    $inputs->attachVariants('settings', by: 'field_type', from: FieldStorageSettingsSchemaVariants::class)
      ->setLabel(t('Settings'));
  }

}
