<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Broken;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;

/**
 * Names an identity key its shape never declares.
 */
#[Resource('resource_test.broken.identity', identity: ['ghost'])]
final class UndeclaredIdentityResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
