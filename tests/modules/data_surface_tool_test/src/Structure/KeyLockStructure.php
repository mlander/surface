<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_tool_test\Resource\CabinetResource;

/**
 * A key lock's settings: open to whoever may edit the cabinet.
 */
#[Structure('tool_test.cabinet.key_lock')]
#[Variant(of: CabinetResource::class, at: 'lock_settings', for: 'key')]
final class KeyLockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('keys', 'integer', t('Keys'), default: 2)
      ->addConstraint('Range', ['min' => 1, 'max' => 5]);
  }

}
