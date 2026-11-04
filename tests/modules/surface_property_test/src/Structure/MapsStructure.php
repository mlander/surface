<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Two anonymous maps, with calls: MapsPropertiesStructure's twin.
 *
 * The second attaches a child, so it needs a frame, and the build runs
 * this declaration again to fill it.
 */
#[Structure('property_test.maps')]
final class MapsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addMap('coordinates', t('Coordinates'), static function (BlueprintInterface $map): void {
      $map->add('lat', 'float', t('Latitude'))
        ->setRequired(TRUE)
        ->addConstraint('Range', ['min' => -90, 'max' => 90]);
      $map->add('lng', 'float', t('Longitude'))
        ->setRequired(TRUE)
        ->addConstraint('Range', ['min' => -180, 'max' => 180]);
    })->setDescription(t('Where it is.'));

    $inputs->addMap('booking', t('Booking'), static function (BlueprintInterface $map): void {
      $map->add('seats', 'integer', t('Seats'), default: 1);
      $map->attach('row', EntryStructure::class)
        ->setLabel(t('Row'));
    });
  }

}
