<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\Plugin\Validation\Constraint;

use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the review deadline constraint.
 */
final class ReviewDeadlineConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ReviewDeadlineConstraint);
    if (!is_array($value)) {
      return;
    }
    $amount = $value[NodeTypeReviewSettings::AMOUNT] ?? NULL;
    $unit = $value[NodeTypeReviewSettings::UNIT] ?? NodeTypeReviewSettings::DEFAULT_UNIT;
    // An amount that is not a whole number, or a unit outside the list,
    // is the amount's or the unit's own constraint's to refuse.
    if (!is_int($amount) || !is_string($unit) || !NodeTypeReviewSettings::isUnit($unit)) {
      return;
    }
    $seconds = NodeTypeReviewSettings::seconds($amount, $unit);
    if ($seconds < NodeTypeReviewSettings::DEADLINE_MIN || $seconds > NodeTypeReviewSettings::DEADLINE_MAX) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@amount', (string) $amount)
        ->setParameter('@unit', $unit)
        ->atPath(NodeTypeReviewSettings::AMOUNT)
        ->addViolation();
    }
  }

}
