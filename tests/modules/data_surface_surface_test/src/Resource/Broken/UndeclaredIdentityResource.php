<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource\Broken;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;

/**
 * Names an identity key its shape never declares.
 */
#[Resource('surface_test.broken.identity', identity: ['ghost'])]
final class UndeclaredIdentityResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
