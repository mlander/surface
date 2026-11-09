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
 * A note that holds a secret, which a stage keeps sealed.
 *
 * A staged write seals the token in the stage's record with the site's
 * codec and records its path; a read through the stage opens it and
 * leaves it out of the answer, as every read does.
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
