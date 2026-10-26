<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a value names an entity type a bare reference key may name.
 *
 * What the `entity_type` of a key declared `add($key, 'entity', ...)`
 * allows, and what its author narrows it by: written on the key, it is
 * moved onto the entity type when the key's two parts are built. An
 * entity type is allowed when it is not internal, implements the
 * interface when one is named, is among the targets when any are named,
 * and the account may reference at least one entity of it, of the
 * target's bundles. The options resolver beside it lists them.
 *
 * @see \Drupal\data_surface\Reference\References::entityTypes()
 * @see \Drupal\data_surface\Plugin\DataSurfaceOptionsResolver\ReferenceableTypeOptions
 */
#[Constraint(
  id: 'DataSurfaceReferenceableType',
  label: new TranslatableMarkup('An entity type the account may reference', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class ReferenceableTypeConstraint extends SymfonyConstraint {

  /**
   * Constructs a ReferenceableTypeConstraint.
   *
   * @param string|null $interface
   *   ContentEntityInterface or ConfigEntityInterface, by class name, for
   *   entity types of that kind alone; NULL for any.
   * @param array<string, string[]> $targets
   *   The entity types allowed, each with the bundles its id may be of
   *   (none for any bundle); empty for every entity type.
   * @param string $message
   *   The error message for an entity type it does not allow.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public ?string $interface = NULL,
    public array $targets = [],
    public string $message = 'The entity type %type cannot be referenced here.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}
