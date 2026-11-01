<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a bundle is one of an entity type the account may create.
 *
 * Which bundles an account may create is the entity type's access control
 * handler's answer, so an entity type's resource points at it with this
 * constraint on its `bundle` key when it creates, and the options
 * resolver beside it lists them; this validator, asking the same handler,
 * refuses any other, so a bundle the account may not create, or one with
 * no form display, is refused at the key that names it.
 *
 * @see \Drupal\surface_examples_entity\Plugin\SurfaceOptionsResolver\CreatableBundleOptions
 */
#[Constraint(
  id: 'SurfaceCreatableBundle',
  label: new TranslatableMarkup('A bundle the account may create', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class CreatableBundleConstraint extends SymfonyConstraint {

  /**
   * Constructs a CreatableBundleConstraint.
   *
   * @param string $entityTypeId
   *   The entity type the bundle is of.
   * @param string $message
   *   The error message if the bundle is not one the account may create.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $entityTypeId = '',
    public string $message = 'You may not create @bundle here: it is no bundle with a form display, or not one you may create.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}
