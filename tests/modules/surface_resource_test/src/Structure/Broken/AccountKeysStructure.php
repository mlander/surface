<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\AccountKeys;

/**
 * Asks for keys from a provider that varies by the account.
 */
#[Structure('resource_test.broken.account_keys')]
final class AccountKeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('own', 'boolean', from: AccountKeys::class);
  }

}
