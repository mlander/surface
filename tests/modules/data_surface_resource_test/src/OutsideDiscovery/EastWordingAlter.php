<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\Relay\PlainRelayResource;

/**
 * Gives the relay's token a placeholder, which no other alter sets.
 *
 * Registered only by the test that says so.
 */
#[Alters(PlainRelayResource::class)]
final class EastWordingAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('token', placeholder: 'tok-...');
  }

}
