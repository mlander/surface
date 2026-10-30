<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

/**
 * Example 2: a value names a room of one venue.
 *
 * Added by the room's refiner beside EventVenueRoomConstraint,
 * `addConstraint('RoomOfEventVenue', ['venue' => $venue])`: one more
 * constraint narrows, and the list both name is the venue's rooms. It has
 * a name of its own because adding EventVenueRoom again with a venue
 * replaces the declared one, and the narrowing check refuses a constraint
 * whose options were replaced, since it cannot read what they mean.
 */
#[Constraint(
  id: 'RoomOfEventVenue',
  label: new TranslatableMarkup('Room of the chosen venue', [], ['context' => 'Validation']),
  type: ['string'],
)]
final class RoomOfEventVenueConstraint extends EventVenueRoomConstraint {}
