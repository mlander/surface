<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_stage_test\LedgerSlug;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\surface_stage_test\Structure\LedgerTouchStructure;

/**
 * A ledger row, by an id its caller names, kept in a key-value collection.
 *
 * What a stage can hold beside a content entity: its storage is a plain
 * database write with no cache, which a transaction rolls back whole. Its
 * slug is computed from its title on every write that changes the title,
 * so a write applied from a stage runs a compute guard inside the stage
 * storage's guard. `touch` is an update over a structure that declares no
 * keys: the call is the whole write.
 */
#[Resource('stage_test.ledger',
  identity: ['id'],
  storage: LedgerStorage::class,
  label: new TranslatableMarkup('Ledger row'),
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a ledger row'),
  permission: self::PERMISSION,
  creates: TRUE,
  idempotent: TRUE,
)]
final class LedgerResource implements ResourceInterface {

  /**
   * The permission every operation of the ledger and the vault note asks.
   */
  public const PERMISSION = 'keep the ledger';

  /**
   * Edits a row, named by its id.
   */
  #[Operation('update', label: new TranslatableMarkup('Edit a ledger row'), permission: self::PERMISSION)]
  public static function update(string $id): OperationContext {
    \Drupal::classResolver(LedgerStorage::class)->named($id, 'The "update" operation of stage_test.ledger');
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Marks a row touched: an update that takes no keys.
   */
  #[Operation('touch',
    label: new TranslatableMarkup('Touch a ledger row'),
    permission: self::PERMISSION,
    structure: LedgerTouchStructure::class,
  )]
  public static function touch(string $id): OperationContext {
    \Drupal::classResolver(LedgerStorage::class)->named($id, 'The "touch" operation of stage_test.ledger');
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Id'))
      ->setRequired(TRUE);

    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);

    $inputs->add('note', 'string', t('Note'));

    $inputs->addList('tags', 'string', t('Tags'));

    $inputs->compute('slug', 'string', t('Slug'), from: ['title'], using: LedgerSlug::class, when: Compute::ON_CHANGE);
  }

}
