<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_examples\Target\RegistrationStep3Target;
use Drupal\data_surface_examples\Venues;

/**
 * Example 3: made of parts.
 *
 * Example 2, plus two parts that are surfaces of their own. The ticket is a
 * slot the pricing chooses: FreeTicketSurface or PaidTicketSurface fills
 * it, each naming this class with #[SurfaceVariant], and this class names
 * neither. The contact is a fixed part, ContactSurface, always there.
 */
#[Surface('registration.step3', target: RegistrationStep3Target::class)]
final class RegistrationStep3Surface implements SurfaceInterface {

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
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', t('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', t('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('pricing', 'string', t('Pricing'), default: 'free')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['free', 'paid']]);
    $inputs->attachBy('ticket', by: 'pricing')
      ->setLabel(t('Ticket'));
    $inputs->attach('contact', ContactSurface::class)
      ->setLabel(t('Contact'));
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   */
  #[RefinesInput('capacity')]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats ?? 1000]);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->setDescription(t('Up to @seats for the @room.', [
        '@seats' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    return $capacity;
  }

}
