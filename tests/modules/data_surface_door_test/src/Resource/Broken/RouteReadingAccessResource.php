<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Resource\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_door_test\Access\Broken\RouteReadingAccess;
use Drupal\data_surface_door_test\Storage\DoorNoteStorage;

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
