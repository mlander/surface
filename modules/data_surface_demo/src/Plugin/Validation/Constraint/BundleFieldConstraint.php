<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a value names a field on one bundle of one entity type.
 *
 * Core says a bundle exists (EntityBundleExists) and nothing says a field
 * does, so the demo block's field list could only be built by a refiner
 * that fetched it. Said as a constraint, the refiner points at the list
 * and hands it the entity type and the bundle, and the options resolver
 * beside it fetches: the rule the sketch states as "a refiner never
 * calls a service", with the module's own resolver mechanism standing in
 * for the sketch's options lists until those are built.
 *
 * @see \Drupal\data_surface_demo\Plugin\DataSurfaceOptionsResolver\BundleFieldOptions
 * @see \Drupal\data_surface_demo\Surface\DemoBlockSurface::fieldOfBundle()
 */
#[Constraint(
  id: 'DataSurfaceDemoBundleField',
  label: new TranslatableMarkup('Field on a bundle', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class BundleFieldConstraint extends SymfonyConstraint {

  /**
   * Constructs a BundleFieldConstraint.
   *
   * @param string $entityTypeId
   *   The entity type.
   * @param string $bundle
   *   The bundle of that entity type.
   * @param string $message
   *   The error message if the value names no field on that bundle.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $entityTypeId = '',
    public string $bundle = '',
    public string $message = "The '@field' field does not exist on @entity_type_id @bundle.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}
