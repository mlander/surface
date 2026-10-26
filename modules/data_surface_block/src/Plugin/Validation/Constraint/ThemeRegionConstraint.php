<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Plugin\Validation\Constraint;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a value names a region a theme shows.
 *
 * The regions a block can be placed in are the theme's visible regions,
 * the ones the block layout offers; with no theme named, the regions any
 * installed theme shows. The options resolver reads this constraint as
 * that list, so the check and the choices offered are one declaration.
 *
 * @see \Drupal\data_surface_block\Plugin\DataSurfaceOptionsResolver\ThemeRegionOptions
 */
#[Constraint(
  id: 'DataSurfaceThemeRegion',
  label: new TranslatableMarkup('Region of a theme', [], ['context' => 'Validation']),
  type: FALSE,
)]
class ThemeRegionConstraint extends SymfonyConstraint {

  /**
   * Constructs a ThemeRegionConstraint.
   *
   * @param string $theme
   *   The theme whose regions the value is held to; empty for the regions
   *   of every installed theme.
   * @param string $message
   *   The error message if the value names no region of the theme.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $theme = '',
    public string $message = 'The @theme theme has no region named @region.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  /**
   * Lists the regions the constraint allows.
   *
   * The one place the option becomes the theme handler's answer, so the
   * validator and the options resolver cannot read the same constraint
   * as two lists.
   *
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themes
   *   The theme handler.
   *
   * @return array<string, string>
   *   Each region's label, by machine name: the theme's, in the order it
   *   declares them, or every installed theme's, the first label of a
   *   region several themes share.
   */
  public function regions(ThemeHandlerInterface $themes): array {
    $names = $this->theme === '' ? array_keys($themes->listInfo()) : [$this->theme];
    $regions = [];
    foreach ($names as $name) {
      if ($themes->themeExists($name)) {
        foreach ($themes->getTheme($name)->listVisibleRegions() as $region => $label) {
          $regions[(string) $region] ??= (string) $label;
        }
      }
    }
    return $regions;
  }

}
