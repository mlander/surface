<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Plugin\Validation\Constraint\ReferenceableTypeConstraint;
use Drupal\data_surface\Reference\References;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Lists the entity types a bare reference key may name, for the account.
 *
 * Each entity type the constraint allows by its interface and its
 * targets, and of which the account may reference at least one entity:
 * one count per entity type, through its selection handler.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
#[DataSurfaceOptionsResolver(
  id: 'data_surface_referenceable_type',
  label: new TranslatableMarkup('Referenceable entity types'),
  constraint: 'DataSurfaceReferenceableType',
  exact: TRUE,
)]
final class ReferenceableTypeOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs a ReferenceableTypeOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\data_surface\Reference\References $references
   *   What reference keys are, and which entities they may name.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly References $references,
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
      $container->get('data_surface.references'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof ReferenceableTypeConstraint);
    [$types, $cacheability] = $this->references->entityTypes($constraint->interface, $constraint->targets);
    return new OptionSet($types, [], $cacheability);
  }

}
