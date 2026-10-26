<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface_block\Plugin\Validation\Constraint\ThemeRegionConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a DataSurfaceThemeRegion constraint as the theme's visible regions.
 *
 * In the order the theme declares them, each labeled as the theme labels
 * it: what the block layout offers for that theme; with no theme named,
 * every installed theme's. Which themes are
 * installed is core.extension's, and a theme's regions are read from its
 * info file, which a cache rebuild reads again, so the answer is cached
 * by that configuration's tag.
 */
#[DataSurfaceOptionsResolver(
  id: 'data_surface_theme_region',
  label: new TranslatableMarkup('Region of a theme'),
  constraint: 'DataSurfaceThemeRegion',
)]
final class ThemeRegionOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs a ThemeRegionOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themes
   *   The theme handler, which knows each installed theme's regions.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly ThemeHandlerInterface $themes,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $constraint_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('validation.constraint'),
      $container->get('theme_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof ThemeRegionConstraint);
    return new OptionSet($constraint->regions($this->themes), [], (new CacheableMetadata())->addCacheTags(['config:core.extension']));
  }

}
