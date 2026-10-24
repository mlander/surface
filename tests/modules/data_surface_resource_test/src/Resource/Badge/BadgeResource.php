<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Badge;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\Derive;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Deriver\BadgeCode;
use Drupal\data_surface_resource_test\Deriver\BadgeHandle;
use Drupal\data_surface_resource_test\Storage\BadgeStorage;

/**
 * A badge: two keys computed from a name, once and on every change.
 *
 * The code is computed once, while nothing is stored for it; the handle
 * again whenever the first or last name changes, and is held to twelve
 * characters, which a long name breaks.
 */
#[Resource('resource_test.badge', storage: BadgeStorage::class)]
#[Operation('configure', label: 'Configure the badge', permission: 'administer site configuration')]
final class BadgeResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('first', 'string', t('First name'))->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 10]);
    $inputs->add('last', 'string', t('Last name'));
    $inputs->derive('code', 'string', t('Code'), from: ['first'], using: BadgeCode::class);
    $inputs->derive('handle', 'string', t('Handle'), from: ['first', 'last'], using: BadgeHandle::class, when: Derive::ON_CHANGE)
      ->addConstraint('Length', ['max' => 12]);
    $inputs->add('color', 'string', t('Color'), default: 'blue');
  }

}
