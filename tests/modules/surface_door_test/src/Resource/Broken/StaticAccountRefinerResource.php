<?php

declare(strict_types=1);

namespace Drupal\surface_door_test\Resource\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_door_test\Storage\DoorNoteStorage;

/**
 * A static refiner that takes the account: refused at build.
 */
#[Resource('door_test.broken.static_account_refiner', storage: DoorNoteStorage::class)]
#[Operation('configure', label: 'Configure the note')]
final class StaticAccountRefinerResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Open');
  }

  /**
   * Shortens the note of a signed-in account, and says it is static.
   */
  #[RefinesInput('note')]
  public static function requiredWhenSignedIn(DataDefinitionInterface $note, AccountInterface $account): DataDefinitionInterface {
    return $account->isAnonymous() ? $note : $note->addConstraint('Length', ['max' => 10]);
  }

}
