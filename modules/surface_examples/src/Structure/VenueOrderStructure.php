<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Example 15: one venue and the weight a reorder gives it.
 */
#[Structure('event.venue.order')]
final class VenueOrderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('id', Schema::string()
      ->title(t('Venue'))
      ->description(t('The machine name of a venue the site has.'))
      ->required());

    $inputs->addSchema('weight', Schema::integer()
      ->title(t('Weight'))
      ->description(t('Its place among the venues: lighter first.'))
      ->minimum(-1000)
      ->maximum(1000)
      ->required());
  }

}
