<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceLinkUri constraint.
 *
 * No value given is not a failure. A value is taken when it is text that
 * starts with a scheme: one `parse_url()` reads, as core's PrimitiveType
 * reads a `uri`, or one RFC 3986 spells (a letter, then letters, digits,
 * `+`, `-` or `.`, then a colon), so every URI the `uri` format takes is
 * taken too.
 */
final class LinkUriConstraintValidator extends ConstraintValidator {

  /**
   * An RFC 3986 scheme and its colon, at the start.
   */
  private const SCHEME = '/^[A-Za-z][A-Za-z0-9+.\-]*:/';

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof LinkUriConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $scheme = is_string($value) ? parse_url($value, PHP_URL_SCHEME) : NULL;
    if (is_string($value) && (preg_match(self::SCHEME, $value) === 1 || (is_string($scheme) && $scheme !== ''))) {
      return;
    }
    $this->context->addViolation($constraint->message);
  }

}
