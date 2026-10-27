<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints\Unique;

/**
 * Checks that no item of a list appears in it twice.
 *
 * The list constraint core is missing beside Count. JSON Schema states a
 * list's size with `minItems` and `maxItems`, which Count checks, and
 * that its items differ with `uniqueItems`, which nothing in core
 * checks: Symfony has the Unique constraint and core does not register
 * it, so every list that should hold each value once is checked by a
 * hand-written constraint or not at all. It is the obvious next list
 * constraint to propose upstream, registered the way core registers
 * Count: Symfony's class, under an id of its own, with a message in
 * Drupal's placeholder style. Nothing here knows about data surfaces;
 * the emitter writes it as `uniqueItems: true` and the schema reader
 * reads that keyword back into it, not the reverse.
 *
 * It takes no option but its message. Symfony's `fields`, `normalizer`
 * and `errorPath` compare part of an item, or an item changed, which
 * `uniqueItems` cannot say, so a list that needs them writes its own.
 *
 * @see \Drupal\data_surface\Plugin\Validation\Constraint\UniqueItemsConstraintValidator
 */
#[Constraint(
  id: 'UniqueItems',
  label: new TranslatableMarkup('Unique items', [], ['context' => 'Validation']),
  type: ['list'],
)]
final class UniqueItemsConstraint extends Unique {

  /**
   * Constructs a UniqueItemsConstraint.
   *
   * @param string|null $message
   *   The error message if an item appears more than once; `%value` is
   *   the first item repeated.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    ?string $message = 'The item %value is listed more than once.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(message: $message, groups: $groups, payload: $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return UniqueItemsConstraintValidator::class;
  }

}
