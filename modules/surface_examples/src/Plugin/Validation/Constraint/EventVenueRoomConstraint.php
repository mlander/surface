<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * Example 2: a value names a room of a venue the site has.
 *
 * Declared on the room with `addConstraint('EventVenueRoom')`; a refiner
 * narrows it to one venue's rooms by adding RoomOfEventVenueConstraint, a
 * pure function of the venue. EventVenueRoomOptions reads both as the
 * rooms. A Choice whose list is the site's, so a schema states the list as
 * an enum and a refusal is in Choice's own words.
 */
#[Constraint(
  id: 'EventVenueRoom',
  label: new TranslatableMarkup('Room of an event venue', [], ['context' => 'Validation']),
  type: ['string'],
)]
class EventVenueRoomConstraint extends Choice {

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

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return EventVenueRoomConstraintValidator::class;
  }

}
