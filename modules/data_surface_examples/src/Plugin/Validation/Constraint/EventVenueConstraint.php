<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * Example 2: a value names a venue an event may be held in.
 *
 * Declared on a key with `addConstraint('EventVenue')`; EventVenueOptions
 * reads it as the venues, so a form offers them and both documents state
 * them. A Choice whose list is the site's, so it refuses a value in
 * Choice's own words. EventVenueRoomConstraint, for a room, is one too, so
 * one resolver reads both.
 */
#[Constraint(
  id: 'EventVenue',
  label: new TranslatableMarkup('Event venue', [], ['context' => 'Validation']),
  type: ['string'],
)]
class EventVenueConstraint extends Choice {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return VenueChoiceValidator::class;
  }

}
