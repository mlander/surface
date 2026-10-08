<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface_demo\Plugin\Validation\Constraint\BundleFieldConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a DataSurfaceDemoBundleField constraint as that bundle's fields.
 *
 * Every field the bundle has, base fields included, each labeled the way
 * its definition labels it: the list the demo block offered when its own
 * refiner fetched it.
 */
#[DataSurfaceOptionsResolver(
  id: 'data_surface_demo_bundle_field',
  label: new TranslatableMarkup('Field on a bundle'),
  constraint: 'DataSurfaceDemoBundleField',
)]
final class BundleFieldOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs a BundleFieldOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, which names a bundle's fields.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
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
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof BundleFieldConstraint);
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($constraint->entityTypeId, $constraint->bundle) as $name => $field) {
      $options[$name] = $field->getLabel();
    }
    $cacheability = (new CacheableMetadata())->addCacheTags(['entity_field_info']);
    return new OptionSet($options, [], $cacheability);
  }

}
