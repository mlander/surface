<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_stage_test\Storage\VaultNoteStorage;

/**
 * A note that holds a secret, which a stage refuses to keep.
 *
 * A stage keeps what it holds in the clear, so every write to a resource
 * that declares a secret is refused in a stage, the secret sent or not.
 */
#[Resource('stage_test.vault_note', storage: VaultNoteStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the vault note'),
  permission: LedgerResource::PERMISSION,
)]
final class VaultNoteResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Label'), default: '');

    $token = $inputs->add('token', 'string', t('Token'), default: '');
    DefinitionMetadata::setSecret($token);
  }

}
