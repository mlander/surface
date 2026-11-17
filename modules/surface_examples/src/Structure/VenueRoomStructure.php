<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Example 15: one room of a venue, an item of its list of rooms.
 *
 * Example 2 offers a room by its id, so the id is unique across the
 * venues, which the venue's storage holds it to.
 */
#[Structure('event.venue.room')]
final class VenueRoomStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('id', Schema::string()
      ->title(t('Room id'))
      ->check('drupal:Regex', [
        'pattern' => '/^[a-z0-9_]+$/D',
        'message' => t('A room id holds only lowercase letters, numbers and underscores.'),
      ])
      ->maxLength(64)
      ->required());

    $inputs->addSchema('label', Schema::string()
      ->title(t('Room name'))
      ->maxLength(128)
      ->required());

    $inputs->addSchema('seats', Schema::integer()
      ->title(t('Seats'))
      ->minimum(1)
      ->maximum(10000)
      ->required());
  }

}
