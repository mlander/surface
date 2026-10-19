<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\Parcel\ParcelResource;

/**
 * Mounts a courier and a customs code on the parcel, with declared rules.
 *
 * Freight needs a customs code, a parcel under 2 kilograms has none, and
 * a parcel with a code declares a value of at least 1: rules on the
 * alter's own key, read by the owner's keys, and on the owner's key, read
 * by its own.
 */
#[Alters(ParcelResource::class)]
final class ParcelAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('courier', 'string', 'Courier', default: 'own');
    $inputs->add('customs', 'string', 'Customs code');
    $inputs->narrow('customs')
      ->when(Condition::equals('service', 'freight'))
      ->require();
    $inputs->narrow('customs')
      ->when(Condition::below('weight', 2))
      ->doesNotApply();
    $inputs->narrow('declared_value')
      ->when(Condition::present('customs'))
      ->constrain('Range', ['min' => 1]);
  }

}
