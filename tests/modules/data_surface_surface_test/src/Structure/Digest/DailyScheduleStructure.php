<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Digest;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_surface_test\Resource\Digest\DigestResource;

/**
 * A daily digest's schedule: nothing to choose.
 */
#[Structure('surface_test.digest.daily')]
#[Variant(of: DigestResource::class, key: 'schedule', value: 'daily')]
final class DailyScheduleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
  }

}
