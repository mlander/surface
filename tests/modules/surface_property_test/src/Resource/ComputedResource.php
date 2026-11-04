<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Resource;

use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_property_test\Compute\Initials;

/**
 * Two computed keys, with calls: ComputedPropertiesResource's twin.
 */
#[Resource('property_test.computed', identity: ['id'])]
final class ComputedResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);

    $inputs->compute('id', 'machine_name', t('Machine name'), from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)
      ->setDescription(t('Computed from the name unless one is given.'))
      ->addConstraint('Length', ['max' => 32]);

    $inputs->compute('initials', 'string', t('Initials'), from: ['label'], using: Initials::class);
  }

}
