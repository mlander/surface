<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Refuses a list of more than one item, read from the typed value.
 */
final class ContextReadingConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ContextReadingConstraint);
    $object = $this->context->getObject();
    if (!$object instanceof TypedDataInterface) {
      throw new \LogicException('The validator was handed no typed value.');
    }
    if ($object->getDataDefinition()->getDataType() === 'list' && is_array($value) && count($value) > 1) {
      $this->context->addViolation($constraint->message);
    }
  }

}
