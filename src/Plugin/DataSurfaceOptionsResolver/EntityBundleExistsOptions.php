<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\EntityBundleExistsConstraint;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads an EntityBundleExists constraint as the bundles of its type.
 *
 * The list lives in site state rather than in the constraint, so it
 * carries the bundle cache tag: a bundle added after the options were
 * read invalidates whatever cached them.
 */
#[DataSurfaceOptionsResolver(
  id: 'entity_bundle_exists',
  label: new TranslatableMarkup('Entity bundle exists'),
  constraint: 'EntityBundleExists',
)]
final class EntityBundleExistsOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs an EntityBundleExistsOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
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
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof EntityBundleExistsConstraint);
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo($constraint->entityTypeId) as $bundle => $info) {
      $options[$bundle] = $info['label'] ?? $bundle;
    }
    $cacheability = (new CacheableMetadata())->addCacheTags(['entity_bundles']);
    return new OptionSet($options, [], $cacheability);
  }

}
