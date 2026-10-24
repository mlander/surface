<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

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
    $inputs->add('id', 'string', t('Room id'))
      ->setRequired(TRUE)
      ->addConstraint('Regex', [
        'pattern' => '/^[a-z0-9_]+$/D',
        'message' => t('A room id holds only lowercase letters, numbers and underscores.'),
      ])
      ->addConstraint('Length', ['max' => 64]);
    $inputs->add('label', 'string', t('Room name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);
    $inputs->add('seats', 'integer', t('Seats'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
  }

}
