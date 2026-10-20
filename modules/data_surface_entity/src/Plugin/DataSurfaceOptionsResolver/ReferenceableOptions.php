<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface_entity\Plugin\Validation\Constraint\ReferenceableConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Lists what a reference field may point at, when it is few enough.
 *
 * The field's selection handler is asked, for the account asking: core's
 * autocomplete and select widgets ask it the same way. Up to the served
 * contract's enumeration cap the targets are the key's options, labelled;
 * past it this resolver does not apply, so the key takes any id and the
 * constraint's validator decides, which the contract says is checked on
 * the server.
 *
 * What the list varies by is what the selection read: the account's
 * permissions decide whether unpublished or blocked targets are offered,
 * and the query's access tag lets a module narrow it further, node grants
 * by the account's grants. Core's handlers for these target types read
 * no more than that (CONTEXTS); any other type's handler may read the
 * account itself (a file's selection offers what the account uploaded),
 * so its list varies by the account, `user`.
 */
#[DataSurfaceOptionsResolver(
  id: 'data_surface_referenceable',
  label: new TranslatableMarkup('Referenceable by a field'),
  constraint: 'DataSurfaceReferenceable',
)]
final class ReferenceableOptions extends DataSurfaceOptionsResolverBase {

  /**
   * The cache contexts core's selection of each target type varies by.
   *
   * Node grants, for a node and for a comment (whose selection asks the
   * commented node's access), vary by the account's grants, which core
   * says with `user.node_grants:view`; the rest by permissions alone. A
   * type not listed varies by the account.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   */
  public const CONTEXTS = [
    'node' => ['user.permissions', 'user.node_grants:view'],
    'comment' => ['user.permissions', 'user.node_grants:view'],
    'user' => ['user.permissions'],
    'taxonomy_term' => ['user.permissions'],
    'media' => ['user.permissions'],
  ];

  /**
   * Constructs a ReferenceableOptions resolver.
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
   *   The field manager.
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection
   *   The selection handlers.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly SelectionPluginManagerInterface $selection,
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
      $container->get('plugin.manager.entity_reference_selection'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Only while the targets are few enough to list.
   */
  public function applies(Constraint $constraint): bool {
    if (!parent::applies($constraint)) {
      return FALSE;
    }
    assert($constraint instanceof ReferenceableConstraint);
    $handler = $this->handler($constraint);
    return $handler !== NULL && $handler->countReferenceableEntities() <= ContractEmitter::ENUMERATION_CAP;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof ReferenceableConstraint);
    $options = [];
    foreach ($this->handler($constraint)?->getReferenceableEntities() ?? [] as $labels) {
      foreach ($labels as $id => $label) {
        $options[$id] = trim(strip_tags((string) $label));
      }
    }
    $field = $this->entityFieldManager->getFieldDefinitions($constraint->entityTypeId, $constraint->bundle)[$constraint->fieldName] ?? NULL;
    $target = (string) $field?->getSetting('target_type');
    $cacheability = (new CacheableMetadata())
      ->addCacheTags($target === '' ? [] : [$target . '_list'])
      ->addCacheContexts(self::CONTEXTS[$target] ?? ['user']);
    return new OptionSet($options, [], $cacheability);
  }

  /**
   * Gets the reference field's selection handler.
   *
   * @param \Drupal\data_surface_entity\Plugin\Validation\Constraint\ReferenceableConstraint $constraint
   *   The constraint naming the field.
   *
   * @return \Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface|null
   *   The handler, or NULL when the field is not there.
   */
  protected function handler(ReferenceableConstraint $constraint): ?SelectionInterface {
    $field = $this->entityFieldManager->getFieldDefinitions($constraint->entityTypeId, $constraint->bundle)[$constraint->fieldName] ?? NULL;
    if ($field === NULL) {
      return NULL;
    }
    return $this->selection->getSelectionHandler($field);
  }

}
