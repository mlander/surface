<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface_examples\Structure\ContactStructure;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\RegistrationStep3Storage;
use Drupal\surface_examples\Venues;

/**
 * Example 3: made of parts.
 *
 * Example 2, plus two parts that are structures of their own. The ticket
 * has variants the pricing chooses between: FreeTicketStructure and
 * PaidTicketStructure, each naming this class with #[Variant], and this
 * class names neither. The contact is a fixed part, ContactStructure,
 * always there.
 */
#[Resource('registration.step3', storage: RegistrationStep3Storage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 3)'),
  description: new TranslatableMarkup('Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep3Resource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
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
      ->addConstraint('Range', ['min' => 1]);

    $inputs->add('pricing', 'string', t('Pricing'), default: 'free')
      ->setRequired(TRUE);

    $inputs->attachVariants('ticket', by: 'pricing')
      ->setLabel(t('Ticket'));

    $inputs->attach('contact', ContactStructure::class)
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
