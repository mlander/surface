<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Resource;

use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_property_test\Compute\Initials;
use PortableSchema\Builder\Schema;

/**
 * Two computed keys, with calls: ComputedPropertiesResource's twin.
 */
#[Resource('property_test.computed', identity: ['id'])]
final class ComputedResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', Schema::string()
      ->title(t('Name'))
      ->maxLength(64)
      ->required());

    $inputs->compute(
      'id',
      Schema::of('drupal:config/machine_name')
        ->title(t('Machine name'))
        ->description(t('Computed from the name unless one is given.'))
        ->check('drupal:Length', ['max' => 32]),
      from: ['label'],
      using: MachineName::class,
      when: Compute::UNLESS_SENT,
    );

    $inputs->compute(
      'initials',
      Schema::string()
        ->title(t('Initials')),
      from: ['label'],
      using: Initials::class,
    );
  }

}
