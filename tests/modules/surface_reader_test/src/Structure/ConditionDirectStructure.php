<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three conditions' settings, straight from config schema.
 *
 * Each less the keys every condition has, which its frame holds.
 */
#[Structure('reader_test.condition_direct')]
final class ConditionDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['request_path', 'response_status', 'user_role']]);

    $inputs->attachVariants('settings', by: 'id', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'block.block.*',
      'visibility.*',
      'id',
    ]);
  }

}
