<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Plugin\Validation\Constraint\ValidReferenceConstraint;
use Drupal\data_surface\Reference\References;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Lists what a reference key may name, when there are few enough.
 *
 * The selection handler for the key's entity type and bundles is asked,
 * for the account asking, so the list is what core's autocomplete would
 * offer that account. Up to the site's limit,
 * `data_surface.settings:reference_list_limit`, the candidates are the
 * key's options, labeled; past it this resolver does not apply, so the key
 * takes any id and the constraint's validator decides.
 *
 * What the list varies by is what the selection read: the type's list
 * tags, the contexts References::CONTEXTS names for it, and the limit.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
#[DataSurfaceOptionsResolver(
  id: 'data_surface_reference',
  label: new TranslatableMarkup('Referenceable entities'),
  constraint: 'DataSurfaceValidReference',
)]
final class ReferenceOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs a ReferenceOptions resolver.
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
   *
   * Only while the candidates are few enough to list.
   */
  public function applies(Constraint $constraint): bool {
    return parent::applies($constraint)
      && $constraint instanceof ValidReferenceConstraint
      && $constraint->entityTypeId !== ''
      && $this->references->listable($constraint->entityTypeId, $constraint->bundles);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof ValidReferenceConstraint);
    return new OptionSet(
      $this->references->candidates($constraint->entityTypeId, $constraint->bundles),
      [],
      $this->references->cacheability($constraint->entityTypeId),
    );
  }

}
