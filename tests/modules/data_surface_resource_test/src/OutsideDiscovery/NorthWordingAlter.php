<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\Relay\PlainRelayResource;

/**
 * Rewords the relay's token, and words its own mount twice.
 *
 * One of three alters that word the token: the south one sets its label
 * too, which the build refuses; the east one sets its placeholder, which
 * composes. Registered only by the test that says so.
 */
#[Alters(PlainRelayResource::class)]
final class NorthWordingAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('token', label: 'North token', help: 'Issued by the north desk.');

    $inputs->add('reviewed', 'boolean', 'Reviewed');

    $inputs->describe('third_party_settings.north_wording', label: 'North');
    $inputs->describe('third_party_settings.north_wording', label: 'North desk');
  }

}
