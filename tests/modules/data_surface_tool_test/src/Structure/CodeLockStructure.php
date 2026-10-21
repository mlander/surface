<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_tool_test\Access\CodeLockAccess;
use Drupal\data_surface_tool_test\Resource\CabinetResource;

/**
 * A code lock's settings: offered locked unless the account sets codes.
 */
#[Structure('tool_test.cabinet.code_lock', access: CodeLockAccess::class)]
#[Variant(of: CabinetResource::class, at: 'lock_settings', for: 'code')]
final class CodeLockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('digits', 'integer', t('Digits'), default: 4)
      ->addConstraint('Range', ['min' => 3, 'max' => 8]);
  }

}
