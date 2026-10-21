<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\Studio\StudioResource;

/**
 * Arranges two of the studio's keys it does not own, and adds none.
 *
 * Gives the name a placeholder and a help text, and weighs the deposit
 * before the rate inside the owner's pricing group: an alter places and
 * rewords an owner's key, never takes what the owner arranged away.
 */
#[Alters(StudioResource::class)]
final class StudioAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('name', arrangement: new Arrangement(placeholder: 'Riverside studio', help: 'Shown on the door.'));
    $inputs->describe('deposit', arrangement: new Arrangement(weight: -1));
  }

}
