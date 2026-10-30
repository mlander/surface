<?php

declare(strict_types=1);

namespace Drupal\surface_mount_test\Alter;

use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\AltersStorageLayoutInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Storage\StorageLayoutInterface;
use Drupal\surface_mount_test\Storage\MinutesLayout;
use Drupal\surface_schema_test\Resource\AliasSettingsResource;

/**
 * Mounts a reviewer and a delay on the alias settings.
 *
 * The reviewer refers to a user, stored by uuid; the delay is asked in
 * minutes and stored as an ISO 8601 duration, which its layout says.
 */
#[Alters(AliasSettingsResource::class)]
final class AliasMountAlter implements AltersInputsInterface, AltersStorageLayoutInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('reviewer', 'entity:user', 'Reviewer');

    $inputs->add('delay', 'integer', 'Delay in minutes');
  }

  /**
   * {@inheritdoc}
   */
  public function storageLayout(): StorageLayoutInterface {
    return new MinutesLayout('delay');
  }

}
