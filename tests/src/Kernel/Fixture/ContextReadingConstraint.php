<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Symfony\Component\Validator\Constraint;

/**
 * A constraint whose validator reads the typed value it is handed.
 *
 * What ConstraintRunnerTest swaps in for one of Surface's plugin ids, as
 * another module's hook_validation_constraint_alter() may: core's entity
 * aware validators read the context object the same way.
 */
final class ContextReadingConstraint extends Constraint {

  /**
   * The message.
   */
  public string $message = 'Refused by the altered class.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return ContextReadingConstraintValidator::class;
  }

}
