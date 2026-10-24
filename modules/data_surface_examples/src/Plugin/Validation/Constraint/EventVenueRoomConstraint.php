<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;

/**
 * Example 2: a value names a room of any venue an event may be held in.
 *
 * Declared on the room with `addConstraint('EventVenueRoom')`; a refiner
 * narrows it to one venue's rooms by adding RoomOfEventVenueConstraint, a
 * pure function of the venue. EventVenueOptions reads both as rooms.
 */
#[Constraint(
  id: 'EventVenueRoom',
  label: new TranslatableMarkup('Room of an event venue', [], ['context' => 'Validation']),
  type: ['string'],
)]
class EventVenueRoomConstraint extends EventVenueConstraint {

  /**
   * Constructs the constraint.
   *
   * @param string|null $venue
   *   Only the rooms of this venue, or NULL for every room.
   * @param mixed ...$options
   *   Every other option Choice accepts, by name.
   */
  #[HasNamedArguments]
  public function __construct(
    public ?string $venue = NULL,
    mixed ...$options,
  ) {
    parent::__construct(...$options);
  }

}
