<?php

declare(strict_types=1);

namespace Drupal\surface_door_test\Resource\Broken;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_door_test\Access\Broken\RouteReadingAccess;
use Drupal\surface_door_test\Storage\DoorNoteStorage;

/**
 * Answered by an access class that reads the route.
 */
#[Resource('door_test.broken.route_reading_access', storage: DoorNoteStorage::class, access: RouteReadingAccess::class)]
#[Operation('configure', label: 'Configure the note')]
final class RouteReadingAccessResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Open');
  }

}
