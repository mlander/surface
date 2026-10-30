<?php

declare(strict_types=1);

namespace Drupal\surface_examples_keys\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Example 14: a value names one of the keys the site manages.
 *
 * Declared on a key with `addConstraint('ManagedKey')`, as core's
 * existence constraints are; ManagedKeyOptions reads it as the list of
 * keys, so a form offers them and both documents state them.
 */
#[Constraint(
  id: 'ManagedKey',
  label: new TranslatableMarkup('Managed key', [], ['context' => 'Validation']),
  type: ['string'],
)]
final class ManagedKeyConstraint extends SymfonyConstraint {

  /**
   * Constructs the constraint.
   *
   * @param string $message
   *   What a value naming no managed key is refused with.
   * @param array|null $groups
   *   The validation groups.
   * @param mixed $payload
   *   The payload.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $message = "The site manages no key named '@key'.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}
