<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Plugin\SurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Attribute\SurfaceOptionsResolver;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Options\SurfaceOptionsResolverBase;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\surface_examples_entity\Plugin\Validation\Constraint\CreatableBundleConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Lists the bundles of an entity type the account may create.
 *
 * Every bundle with a form display whose create access the entity type's
 * access control handler allows, under its label: the bundles an entity
 * type's resource offers on add. What the list varies by is what the
 * answers read: the bundle list's tags and each create access answer's
 * cacheability, `user.permissions` for core's node types.
 */
#[SurfaceOptionsResolver(
  id: 'surface_creatable_bundle',
  label: new TranslatableMarkup('Bundles the account may create'),
  constraint: 'SurfaceCreatableBundle',
  exact: TRUE,
)]
final class CreatableBundleOptions extends SurfaceOptionsResolverBase {

  /**
   * Constructs a CreatableBundleOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   Which bundles have a form display, and which of them may be created.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly FieldItemDerivation $derivation,
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
      $container->get('surface_examples_entity.field_item_derivation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof CreatableBundleConstraint);
    [$creatable, $cacheability] = $this->derivation->creatableBundles($constraint->entityTypeId);
    return new OptionSet(array_map('strval', $creatable), [], $cacheability);
  }

}
