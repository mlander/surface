<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Journal;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\LedgerStorage;
use Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * A ledger: the timestamps structure nested, where the journal mixes it in.
 *
 * The other composition of the same structure: its keys under the
 * ledger's `stamps`, in a frame of their own, its def the one the
 * journal's document writes open.
 */
#[Resource('resource_test.ledger', storage: LedgerStorage::class)]
final class LedgerResource implements ResourceInterface {

  /**
   * Configures the ledger, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the ledger', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);
    $inputs->attach('stamps', TimestampsStructure::class)
      ->setLabel(t('Stamps'));
  }

}
