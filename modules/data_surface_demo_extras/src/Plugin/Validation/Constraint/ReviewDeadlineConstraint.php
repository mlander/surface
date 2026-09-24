<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that an amount and a unit make a deadline in the allowed range.
 *
 * The range is a property of the stored seconds, one hour to thirty
 * days, and the surface asks for an amount and a unit. Neither half
 * alone can say whether "45 days" is too long, so the check sits on the
 * pair and reports on the amount, which is what a caller changes to fix
 * it, in the units the caller used.
 */
#[Constraint(
  id: 'DataSurfaceDemoExtrasReviewDeadline',
  label: new TranslatableMarkup('Review deadline within range', [], ['context' => 'Validation']),
)]
final class ReviewDeadlineConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'A review deadline is at least one hour and at most thirty days; @amount @unit is outside that.';

}
