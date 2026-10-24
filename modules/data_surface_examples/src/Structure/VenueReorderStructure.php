<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 15: what a reorder takes, a list of venues and their weights.
 *
 * The keys of the venue resource's reorder operation, which names this
 * structure with `structure:`: the venue's own keys are not what a reorder
 * changes.
 */
#[Structure('event.venue.reorder')]
final class VenueReorderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('venues', VenueOrderStructure::class, min: 1)
      ->setLabel(t('Venues'))
      ->setDescription(t('Each venue to move, once, with its new weight; a venue left out keeps its own.'))
      ->setRequired(TRUE);
  }

}
