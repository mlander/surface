<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Resource\Parcel\ParcelResource;
use PortableSchema\Builder\Schema;

/**
 * Mounts a customs code on the parcel, with declared rules.
 *
 * Freight needs a customs code, a parcel under 2 kilograms has none, and
 * a parcel with a code declares a value of at least 1: rules on the
 * alter's own key, read by the declaring class's keys, and on the declaring
 * class's key, read by its own.
 */
#[Alters(ParcelResource::class)]
final class ParcelAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('customs', Schema::string()
      ->title('Customs code'));
    $inputs->narrow('customs')
      ->when(Condition::equals('service', 'freight'))
      ->require();
    $inputs->narrow('customs')
      ->when(Condition::below('weight', 2))
      ->doesNotApply();
    $inputs->narrow('declared_value')
      ->when(Condition::present('customs'))
      ->constrain(Schema::any()->minimum(1));
  }

}
