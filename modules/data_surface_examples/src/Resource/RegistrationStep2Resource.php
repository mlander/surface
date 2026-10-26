<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\RegistrationStep2Storage;
use Drupal\data_surface_examples\Venues;

/**
 * Example 2: answers depend on answers.
 *
 * Example 1's keys, plus a venue and a room. Which rooms are offered
 * depends on the venue, and how many people fit depends on the room:
 * one #[RefinesInput] method each, whose signature names what it reads.
 * Declaration order is form order, so the capacity comes after the room
 * that decides it, and its description names that room's limit. The
 * venue refers to one of example 15's venue entities by its machine name;
 * the room names a room of one, by a constraint a resolver lists; the
 * seats are Venues', since a static refiner reads only what it watches.
 */
#[Resource('registration.step2', storage: RegistrationStep2Storage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 2)'),
  description: new TranslatableMarkup('Sets the event title, whether registration is open, the venue, a room at that venue and a capacity the room can hold.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep2Resource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
    $inputs->add('venue', 'entity:event_venue', t('Venue'))
      ->setRequired(TRUE);
    $inputs->add('room', 'string', t('Room'))
      ->setRequired(TRUE)
      ->addConstraint('EventVenueRoom');
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1]);
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('RoomOfEventVenue', ['venue' => $venue]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   *
   * A room that seats no known number changes nothing: the capacity keeps
   * its lower bound and has no upper one.
   */
  #[RefinesInput('capacity')]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
        ->setDescription(t('Up to @seats for the @room.', [
          '@seats' => $seats,
          '@room' => Venues::rooms()[$room],
        ]));
    }
    return $capacity;
  }

}
