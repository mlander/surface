<?php

declare(strict_types=1);

namespace Drupal\address\Surface;

use Drupal\address\Options\CountryOptions;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\field\Surface\FieldInstanceSurface;
use Drupal\surface_sketch\Surface\Attribute\RefinesInput;
use Drupal\surface_sketch\Surface\Attribute\Surface;
use Drupal\surface_sketch\Surface\Attribute\SurfaceVariant;
use Drupal\surface_sketch\Surface\ShapeInterface;
use Drupal\surface_sketch\Surface\SurfaceInterface;

/**
 * Settings only an address field has. Carries its own refinement.
 */
#[Surface('field.settings.address')]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'address')]
final class AddressFieldSettingsSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->addDefinition('available_countries', ListDataDefinition::create('string')
      ->setLabel('Available countries')
      ->setItemDefinition(DataDefinition::create('string')
        ->addConstraint('OptionsList', ['source' => CountryOptions::class])), default: []);

    $inputs->add('default_country', 'string', 'Default country')
      ->addConstraint('OptionsList', ['source' => CountryOptions::class]);
  }

  /**
   * The default must be one of the countries made available.
   */
  #[RefinesInput('default_country')]
  public function defaultAmongAvailable(DataDefinitionInterface $default_country, array $available_countries): DataDefinitionInterface {
    return $available_countries === []
      ? $default_country
      : $default_country->addConstraint('Choice', ['choices' => $available_countries]);
  }

}
