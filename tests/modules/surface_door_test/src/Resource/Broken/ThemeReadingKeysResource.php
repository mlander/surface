<?php

declare(strict_types=1);

namespace Drupal\surface_door_test\Resource\Broken;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_door_test\Keys\Broken\ThemeReadingKeys;
use Drupal\surface_door_test\Storage\DoorNoteStorage;

/**
 * A key per thing a provider lists from the theme a page renders in.
 */
#[Resource('door_test.broken.theme_reading_keys', storage: DoorNoteStorage::class)]
#[Operation('configure', label: 'Configure the note')]
final class ThemeReadingKeysResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('shown', 'boolean', from: ThemeReadingKeys::class, default: FALSE);
  }

}
