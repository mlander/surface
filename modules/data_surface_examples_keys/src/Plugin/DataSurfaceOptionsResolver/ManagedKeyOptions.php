<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_keys\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface_examples_keys\Keys\ManagedKeys;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Example 14: reads the ManagedKey constraint as the keys the site manages.
 *
 * Each key by its machine name, offered under its label, cacheable by the
 * configuration the keys are listed in.
 */
#[DataSurfaceOptionsResolver(
  id: 'managed_key',
  label: new TranslatableMarkup('Managed key'),
  constraint: 'ManagedKey',
)]
final class ManagedKeyOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs the resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\data_surface_examples_keys\Keys\ManagedKeys $keys
   *   The keys the site manages.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly ManagedKeys $keys,
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
      $container->get('data_surface_examples_keys.keys'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    return new OptionSet($this->keys->labels(), [], $this->keys->cacheability());
  }

}
