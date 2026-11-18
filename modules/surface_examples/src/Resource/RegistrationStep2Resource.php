<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\RegistrationStep2Storage;
use Drupal\surface_examples\Venues;
use PortableSchema\Builder\Schema;

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

  public const TITLE = 'title';
  public const OPEN = 'open';
  public const VENUE = 'venue';
  public const ROOM = 'room';
  public const CAPACITY = 'capacity';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::TITLE, Schema::string()
      ->title(t('Event title'))
      ->required());

    $inputs->add(self::OPEN, Schema::bool()
      ->title(t('Registration open'))
      ->default(TRUE));

    $inputs->add(self::VENUE, Schema::string()
      ->title(t('Venue'))
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'event_venue'])
      ->required());

    $inputs->add(self::ROOM, Schema::string()
      ->title(t('Room'))
      ->check('drupal:EventVenueRoom')
      ->required());

    $inputs->add(self::CAPACITY, Schema::integer()
      ->title(t('Capacity'))
      ->default(50)
      ->minimum(1));
  }

  /**
   * The room must be one of the chosen venue's rooms.
   *
   * The parameter $venue watches self::VENUE by its name, the one place the
   * key is spelled as a string: rename the key, rename the parameter.
   */
  #[RefinesInput(self::ROOM)]
  public static function roomInVenue(string $venue): Schema {
    return Schema::any()->check('drupal:RoomOfEventVenue', ['venue' => $venue]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   *
   * A room that seats no known number changes nothing: the capacity keeps
   * its lower bound and has no upper one. The parameter $room watches
   * self::ROOM by its name.
   */
  #[RefinesInput(self::CAPACITY)]
  public static function capacityOfRoom(string $room): Schema {
    $seats = Venues::seats($room);
    if ($seats === NULL) {
      return Schema::any();
    }
    return Schema::any()
      ->maximum($seats)
      ->description(t('Up to @seats for the @room.', [
        '@seats' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
  }

}
