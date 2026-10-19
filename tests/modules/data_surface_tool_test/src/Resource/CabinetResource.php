<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool_test\Access\CabinetAccess;
use Drupal\data_surface_tool_test\Storage\CabinetStorage;

/**
 * A cabinet whose access may lock it whole, and one of whose locks too.
 *
 * CabinetAccess answers open for a keeper, locked for an auditor and
 * omitted for anyone else, so asked on its own the cabinet is shown and
 * fixed to an auditor. Its lock settings are a slot chosen by the lock:
 * a key lock is open, a code lock is offered locked unless the account
 * may set codes (CodeLockAccess). Counting the drawers is an operation
 * that changes nothing.
 */
#[Resource('tool_test.cabinet', storage: CabinetStorage::class, access: CabinetAccess::class)]
final class CabinetResource implements ResourceInterface {

  /**
   * Edits the cabinet, which always exists.
   */
  #[Operation('edit', label: 'Edit the cabinet')]
  public static function edit(): OperationContext {
    return new OperationContext('edit');
  }

  /**
   * Counts the drawers: changes nothing.
   */
  #[Operation('count', label: 'Count the drawers', readOnly: TRUE)]
  public static function count(): OperationContext {
    return new OperationContext('count');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Label'), default: 'Cabinet');
    $inputs->add('drawers', 'integer', t('Drawers'), default: 4)
      ->addConstraint('Range', ['min' => 1, 'max' => 12]);
    $inputs->add('lock', 'string', t('Lock'), default: 'key')
      ->addConstraint('Choice', ['choices' => ['key', 'code']]);
    $inputs->attachBy('lock_settings', by: 'lock')
      ->setLabel(t('Lock settings'));
  }

}
