<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\UniqueValidator;

/**
 * Validates the UniqueItems constraint.
 *
 * Symfony's UniqueValidator, which compares items strictly: two items are
 * the same when `===` says so, the same type and value, and for a map the
 * same keys in the same order. Typed data hands a validator a list's
 * plain values where its item type unwraps (a scalar, a map), and the
 * list object where it does not (a field item list), whose items are
 * objects that never compare equal, so a list object is turned into its
 * plain values first. A list a data surface accepted has
 * every scalar typed by the coercion table and every map's keys in
 * declaration order, so on such a list strict comparison agrees with
 * `uniqueItems`, which compares by value.
 *
 * No value given is not a failure, as for Count; a value that is no list
 * is its type's to refuse.
 */
final class UniqueItemsConstraintValidator extends UniqueValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof UniqueItemsConstraint);
    if ($value instanceof TypedDataInterface) {
      $value = $value->getValue();
    }
    if (!is_array($value)) {
      return;
    }
    parent::validate($value, $constraint);
  }

}
