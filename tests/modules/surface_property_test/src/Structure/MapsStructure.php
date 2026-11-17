<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->addMap(
      'coordinates',
      t('Coordinates'),
      static function (BlueprintInterface $map): void {
        $map->addSchema('lat', Schema::number()
          ->title(t('Latitude'))
          ->minimum(-90)
          ->maximum(90)
          ->required());
        $map->addSchema('lng', Schema::number()
          ->title(t('Longitude'))
          ->minimum(-180)
          ->maximum(180)
          ->required());
      },
      description: t('Where it is.'),
    );

    $inputs->addMap('booking', t('Booking'), static function (BlueprintInterface $map): void {
      $map->addSchema('seats', Schema::integer()
        ->title(t('Seats'))
        ->default(1));
      $map->attach('row', EntryStructure::class, t('Row'));
    });
  }

}
