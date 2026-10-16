<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that an id names an entity one reference field may point at.
 *
 * What a reference field may hold is its selection handler's answer
 * (target bundles, access, a view), which lives in site state and may
 * depend on the account. So the item surface points at it with this
 * constraint, and the options resolver beside it lists the targets when
 * there are few enough to list; past that the key is free and only this
 * validator, asking the same handler, decides.
 *
 * @see \Drupal\data_surface_entity\Plugin\DataSurfaceOptionsResolver\ReferenceableOptions
 */
#[Constraint(
  id: 'DataSurfaceReferenceable',
  label: new TranslatableMarkup('Referenceable by a field', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class ReferenceableConstraint extends SymfonyConstraint {

  /**
   * Constructs a ReferenceableConstraint.
   *
   * @param string $entityTypeId
   *   The entity type the field is on.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The reference field.
   * @param string $message
   *   The error message if the id names nothing the field may reference.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $entityTypeId = '',
    public string $bundle = '',
    public string $fieldName = '',
    public string $message = 'The @field field cannot refer to @id.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}
