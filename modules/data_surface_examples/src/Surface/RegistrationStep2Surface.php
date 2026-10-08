<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_examples\Target\RegistrationStep2Target;
use Drupal\data_surface_examples\Venues;

/**
 * Example 2: answers depend on answers.
 *
 * Example 1's keys, plus a venue and a room. Which rooms are offered
 * depends on the venue, and how many people fit depends on the room:
 * one #[RefinesInput] method each, whose signature names what it reads.
 * Declaration order is form order, so the capacity comes after the room
 * that decides it, and its description names that room's limit.
 */
#[Surface('registration.step2', target: RegistrationStep2Target::class)]
final class RegistrationStep2Surface implements SurfaceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Situation('configure', label: 'Configure registration', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', new TranslatableMarkup('Event title'))
      ->setRequired(TRUE);
    $inputs->add('open', 'boolean', new TranslatableMarkup('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', new TranslatableMarkup('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', new TranslatableMarkup('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
    $inputs->add('capacity', 'integer', new TranslatableMarkup('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   */
  #[RefinesInput('capacity')]
  public function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats ?? 1000]);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->setDescription(new TranslatableMarkup('Up to @seats for the @room.', [
        '@seats' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    return $capacity;
  }

}
