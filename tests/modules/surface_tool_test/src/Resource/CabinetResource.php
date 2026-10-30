<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_tool_test\Access\CabinetAccess;
use Drupal\surface_tool_test\Storage\CabinetStorage;

/**
 * A cabinet whose access may lock it whole, and one of whose locks too.
 *
 * CabinetAccess answers open for a keeper, locked for an auditor and
 * omitted for anyone else, so asked on its own the cabinet is shown and
 * fixed to an auditor. Its lock settings are variants the lock chooses:
 * a key lock is open, a code lock is offered locked unless the account
 * may set codes (CodeLockAccess). Counting the drawers is an operation
 * that changes nothing.
 */
#[Resource('tool_test.cabinet', storage: CabinetStorage::class, access: CabinetAccess::class)]
#[Operation('update',
  label: 'Edit the cabinet',
  description: 'Edits the cabinet, which always exists.',
)]
#[Operation('count',
  label: 'Count the drawers',
  description: 'Counts the drawers, and changes nothing.',
  readOnly: TRUE,
)]
final class CabinetResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Label'), default: 'Cabinet');
    $inputs->add('drawers', 'integer', t('Drawers'), default: 4)
      ->addConstraint('Range', ['min' => 1, 'max' => 12]);
    $inputs->add('lock', 'string', t('Lock'), default: 'key')
      ->addConstraint('Choice', ['choices' => ['key', 'code']]);
    $inputs->attachVariants('lock_settings', by: 'lock')
      ->setLabel(t('Lock settings'));
  }

}
