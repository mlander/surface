<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Plugin\SurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Attribute\SurfaceOptionsResolver;
use Drupal\surface\Options\SurfaceOptionsResolverBase;
use Drupal\surface\Options\OptionSet;
use Drupal\surface_examples_plugins\Plugin\Validation\Constraint\BundleFieldConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a SurfaceExamplesBundleField constraint as that bundle's fields.
 *
 * Every field the bundle has, base fields included, each labeled the way
 * its definition labels it: the list the example block offered when its own
 * refiner fetched it.
 */
#[SurfaceOptionsResolver(
  id: 'surface_examples_plugins_bundle_field',
  label: new TranslatableMarkup('Field on a bundle'),
  constraint: 'SurfaceExamplesBundleField',
)]
final class BundleFieldOptions extends SurfaceOptionsResolverBase {

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
