<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras;

use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * Writes the review deadline down as seconds, and reads it back.
 *
 * The surface asks for the deadline the way a person says it, an amount
 * and a unit; the node type stores one integer of seconds, the same
 * integer core's content type form stores through this module's form
 * alter. This is the whole of the distance between the two, handed to
 * the surface at build time so that whichever target writes this
 * module's third party settings applies it, and nothing but this
 * module's namespace passes through it.
 *
 * The conversion is NodeTypeReviewSettings::seconds(), business days
 * included, the same the form alter uses. fromStorage() reads seconds
 * back in the largest fixed unit that divides them exactly, so seven
 * days comes back as one week — the same duration, said once. The
 * duration always round-trips; the unit does not always: stored seconds
 * carry no unit, so ten business days come back as twelve days, as
 * NodeTypeReviewSettings::BUSINESS_DAYS explains. A stored value that is
 * not whole hours, which neither this surface nor the form alter writes,
 * reads back as no amount.
 */
final class ReviewDeadlineShape implements SettingsShapeInterface {

  /**
   * {@inheritdoc}
   */
  public function toStorage(array $values): array {
    if (!array_key_exists(NodeTypeReviewSettings::DEADLINE, $values)) {
      return $values;
    }
    $deadline = $values[NodeTypeReviewSettings::DEADLINE];
    $amount = is_array($deadline) ? ($deadline[NodeTypeReviewSettings::AMOUNT] ?? NULL) : NULL;
    $unit = is_array($deadline) ? ($deadline[NodeTypeReviewSettings::UNIT] ?? NULL) : NULL;
    $values[NodeTypeReviewSettings::DEADLINE] = is_int($amount)
      ? NodeTypeReviewSettings::seconds($amount, is_string($unit) ? $unit : NodeTypeReviewSettings::DEFAULT_UNIT)
      : NULL;
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function fromStorage(array $settings): array {
    if (!array_key_exists(NodeTypeReviewSettings::DEADLINE, $settings)) {
      return $settings;
    }
    $seconds = $settings[NodeTypeReviewSettings::DEADLINE];
    $split = is_int($seconds) ? NodeTypeReviewSettings::split($seconds) : NULL;
    $settings[NodeTypeReviewSettings::DEADLINE] = $split ?? [
      NodeTypeReviewSettings::AMOUNT => NULL,
      NodeTypeReviewSettings::UNIT => NodeTypeReviewSettings::DEFAULT_UNIT,
    ];
    return $settings;
  }

}
