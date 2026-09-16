<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a machine name is not already a content type.
 *
 * The demo's service-consulting constraint: whether a machine name is
 * taken can only be answered by asking storage, which static schema
 * cannot do, but a definition-level constraint can, and then the same
 * check runs in the generated form, in a payload validated without a
 * form, and in anything else built on the surface.
 */
#[Constraint(
  id: 'DataSurfaceUniqueNodeType',
  label: new TranslatableMarkup('Unique content type machine name'),
)]
final class UniqueNodeTypeConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'A content type with the machine name %value already exists.';

}
