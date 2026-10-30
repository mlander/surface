<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Badge;

use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Compute\BadgeCode;
use Drupal\surface_resource_test\Compute\BadgeHandle;
use Drupal\surface_resource_test\Storage\BadgeStorage;

/**
 * A badge: three keys computed from a name, once, on change, unless sent.
 *
 * The code is computed once, while nothing is stored for it; the handle
 * again whenever the first or last name changes, and is held to twelve
 * characters, which a long name breaks; the tag, a machine name of eight
 * characters at most, from the last name when none is sent, and kept as
 * sent when one is.
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
    $inputs->compute('code', 'string', t('Code'), from: ['first'], using: BadgeCode::class);
    $inputs->compute('handle', 'string', t('Handle'), from: ['first', 'last'], using: BadgeHandle::class, when: Compute::ON_CHANGE)
      ->addConstraint('Length', ['max' => 12]);
    $inputs->compute('tag', 'machine_name', t('Tag'), from: ['last'], using: MachineName::class, when: Compute::UNLESS_SENT)
      ->addConstraint('Length', ['max' => 8]);
    $inputs->add('color', 'string', t('Color'), default: 'blue');
  }

}
