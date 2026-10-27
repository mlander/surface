<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\Studio\StudioResource;

/**
 * Arranges two of the studio's keys it does not own, and adds a fee.
 *
 * Gives the name a placeholder and a help text, and weighs the deposit
 * before the rate inside the owner's pricing group: an alter places and
 * rewords an owner's key, never takes what the owner arranged away. Its
 * booking fee is placed after the deposit, a key the pricing group holds,
 * so every door draws it inside that group, right after the deposit.
 */
#[Alters(StudioResource::class)]
final class StudioAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('name', placeholder: 'Riverside studio', help: 'Shown on the door.');
    $inputs->describe('deposit', weight: -1);
    $inputs->add('booking_fee', 'integer', 'Booking fee', default: 0)
      ->addConstraint('Range', ['min' => 0]);
    $inputs->describe('third_party_settings.data_surface_resource_test', label: 'Booking', after: 'deposit');
  }

}
