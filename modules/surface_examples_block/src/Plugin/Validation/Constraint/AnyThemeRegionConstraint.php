<?php

declare(strict_types=1);

namespace Drupal\surface_examples_block\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;

/**
 * Checks that a value names a region some installed theme shows.
 *
 * What a region allows before a theme is chosen: every installed theme's
 * visible regions, so every door offers a list from the start. The theme
 * chosen adds SurfaceThemeRegion beside it, whose list is a part of
 * this one, and the options resolver answers the two together as that
 * theme's.
 *
 * @see \Drupal\surface_examples_block\Plugin\SurfaceOptionsResolver\ThemeRegionOptions
 */
#[Constraint(
  id: 'SurfaceAnyThemeRegion',
  label: new TranslatableMarkup('Region of an installed theme', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class AnyThemeRegionConstraint extends ThemeRegionConstraint {

  /**
   * Constructs an AnyThemeRegionConstraint.
   *
   * @param string $message
   *   The error message if no installed theme has the region.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    string $message = 'No installed theme has a region named @region.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct('', $message, $groups, $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return ThemeRegionConstraintValidator::class;
  }

}
