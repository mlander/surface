<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Resource\Studio\StudioResource;
use PortableSchema\Builder\Schema;

/**
 * Arranges two of the studio's keys it does not own, and adds a fee.
 *
 * Gives the name a placeholder and a help text, and weighs the deposit
 * before the rate inside the declaring class's pricing group: an alter places
 * and rewords a declaring class's key, never takes what the declaring class
 * arranged away. Its booking fee is placed after the deposit, a key the pricing
 * group holds, so every door draws it inside that group, right after the
 * deposit.
 */
#[Alters(StudioResource::class)]
final class StudioAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('name', placeholder: 'Riverside studio', help: 'Shown on the door.');
    $inputs->describe('deposit', weight: -1);
    $inputs->add('booking_fee', Schema::integer()
      ->title('Booking fee')
      ->default(0)
      ->minimum(0));
    $inputs->describe('third_party_settings.surface_resource_test', label: 'Booking', after: 'deposit');
  }

}
