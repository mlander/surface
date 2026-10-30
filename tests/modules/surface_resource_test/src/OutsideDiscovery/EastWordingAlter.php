<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Resource\Relay\PlainRelayResource;

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
