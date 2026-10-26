<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Resource\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_door_test\Storage\Broken\RequestReadingStorage;

/**
 * Stored by a storage that reads the request.
 */
#[Resource('door_test.broken.request_reading_storage', storage: RequestReadingStorage::class)]
#[Operation('configure', label: 'Configure the note')]
final class RequestReadingStorageResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Open');
  }

}
