<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity_test\Plugin\Validation\Constraint;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the DataSurfaceEntityTestTitle constraint.
 */
final class TitleConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof TitleConstraint);
    if (!$value instanceof ContentEntityInterface || !$value->hasField('title')) {
      return;
    }
    $title = (string) $value->get('title')->value;
    if ($title === 'Forbidden') {
      $this->context->buildViolation($constraint->message, ['@title' => $title])->atPath('title.0.value')->addViolation();
    }
    if ($title === 'Refused whole') {
      $this->context->addViolation($constraint->wholeMessage);
    }
  }

}
