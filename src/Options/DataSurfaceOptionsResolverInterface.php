<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads one kind of constraint as a list of allowed values.
 *
 * The mechanism that lets a value list be declared once. A constraint
 * says which values are allowed; a resolver says what that set of values
 * is and what each one is called, without the consumer having to know
 * which constraint it came from.
 */
interface DataSurfaceOptionsResolverInterface {

  /**
   * Returns whether this resolver can read a constraint.
   */
  public function applies(Constraint $constraint): bool;

  /**
   * Reads a constraint as a list of allowed values.
   *
   * @param \Symfony\Component\Validator\Constraint $constraint
   *   The constraint, instantiated from the definition.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the constraint was declared on, for resolvers that
   *   need the surrounding context.
   *
   * @return \Drupal\data_surface\Options\OptionSet
   *   The allowed values with their labels and cacheability.
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet;

}
