<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Rewords the timestamps' created time, wherever the structure is used.
 *
 * Attached or mixed in alike: an alter of a structure applies wherever
 * the structure is, its members being the owner's keys where it is
 * mixed in.
 */
#[Alters(TimestampsStructure::class)]
final class TimestampsAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('created', description: 'Seconds since the epoch.');
  }

}
