<?php

declare(strict_types=1);

namespace Drupal\surface\Options;

use PortableSchema\Builder\Schema;
use Symfony\Component\Validator\Constraint;

/**
 * Reads one kind of constraint as a list of allowed values.
 *
 * The mechanism that lets a value list be declared once. A constraint
 * says which values are allowed; a resolver says what that set of values
 * is and what each one is called, without the consumer having to know
 * which constraint it came from.
 */
interface SurfaceOptionsResolverInterface {

  /**
   * Returns whether this resolver can read a constraint.
   */
  public function applies(Constraint $constraint): bool;

  /**
   * Reads a constraint as a list of allowed values.
   *
   * @param \Symfony\Component\Validator\Constraint $constraint
   *   The constraint, instantiated from the key's constraints.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema of the key the constraint was declared on, for a resolver
   *   that needs the surrounding context. What it promises is the
   *   standard keywords, read through get(): its title, its type, its
   *   bounds. What Surface keeps on a schema beyond them is not promised
   *   and may change.
   *
   * @return \Drupal\surface\Options\OptionSet
   *   The allowed values with their labels and cacheability.
   */
  public function resolve(Constraint $constraint, Schema $schema): OptionSet;

}
