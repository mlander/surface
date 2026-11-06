<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface_reader_test\Variants\ConditionPluginJsonStructures;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three conditions' settings, through the JSON Schema route.
 */
#[Structure('reader_test.condition_json')]
final class ConditionJsonStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['request_path', 'response_status', 'user_role']]);

    $inputs->attachVariants('settings', by: 'id', from: ConditionPluginJsonStructures::class)
      ->setLabel(t('Settings'));
  }

}
