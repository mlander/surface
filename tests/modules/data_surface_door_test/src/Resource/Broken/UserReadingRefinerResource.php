<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Resource\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_door_test\Storage\DoorNoteStorage;

/**
 * A runtime refiner that reads the current user itself: refused.
 *
 * The account the operation runs as is handed to a parameter typed for
 * it; reading the current user instead is one door's account at every
 * door.
 */
#[Resource('door_test.broken.user_reading_refiner', storage: DoorNoteStorage::class)]
#[Operation('configure', label: 'Configure the note')]
final class UserReadingRefinerResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Open');
  }

  /**
   * Shortens the note of a signed-in account, read from the current user.
   */
  #[RefinesInput('note', runtime: TRUE)]
  public static function requiredWhenSignedIn(DataDefinitionInterface $note): DataDefinitionInterface {
    return \Drupal::currentUser()->isAnonymous() ? $note : $note->addConstraint('Length', ['max' => 10]);
  }

}
