<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Digest;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_surface_test\Resource\Digest\DigestResource;

/**
 * A weekly digest's schedule: the day it is sent.
 */
#[Structure('surface_test.digest.weekly')]
#[Variant(of: DigestResource::class, key: 'schedule', value: 'weekly')]
final class WeeklyScheduleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('send_day', 'string', 'Send day', default: 'monday')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['monday', 'friday']]);
  }

}
