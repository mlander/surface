<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Access;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\SurfaceAccess;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field access, as a field item child's access: open, locked or omitted.
 *
 * The entity's access control handler is asked about the field twice,
 * with the field's items: may this account edit it (open), and if not,
 * may it view it (locked: shown and fixed), and if not, the field is not
 * there at all (omitted). The items are the entity's own when the
 * context knows its id, and a throwaway entity of the bundle's when it
 * is being created, as core's own form makes one: fieldAccess() takes
 * items, and a field has none without an entity. What both answers read
 * is carried on the answer, so the surface built from it varies by it.
 *
 * Decision: see docs/decisions.md#field-access-is-child-access.
 */
final class FieldItemAccess implements SurfaceAccessInterface, ContainerInjectionInterface {

  /**
   * The throwaway entities made so far, by entity type and bundle.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  protected array $stubs = [];

  /**
   * Constructs a FieldItemAccess.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function access(SurfaceContext $context, AccountInterface $account): SurfaceAccess {
    $entity = $this->entity($context);
    $field_name = (string) ($context->known['field_name'] ?? '');
    if ($entity === NULL || !$entity->hasField($field_name)) {
      return SurfaceAccess::omitted(sprintf('There is no %s field to ask about.', $field_name));
    }
    $items = $entity->get($field_name);
    $handler = $this->entityTypeManager->getAccessControlHandler($entity->getEntityTypeId());
    $edit = $handler->fieldAccess('edit', $items->getFieldDefinition(), $account, $items, TRUE);
    if ($edit->isAllowed()) {
      return SurfaceAccess::open()->addCacheableDependency($edit);
    }
    $view = $handler->fieldAccess('view', $items->getFieldDefinition(), $account, $items, TRUE);
    $answer = $view->isAllowed()
      ? SurfaceAccess::locked(sprintf('The %s field may be seen and not changed.', $field_name))
      : SurfaceAccess::omitted(sprintf('The %s field may not be seen.', $field_name));
    return $answer->addCacheableDependency($edit)->addCacheableDependency($view);
  }

  /**
   * Gets the entity the field's items belong to.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The item's context, knowing the entity type, the bundle and, for
   *   an entity that exists, its id.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity, a throwaway one of the bundle when there is no id, or
   *   NULL when the id names nothing.
   */
  protected function entity(SurfaceContext $context): ?ContentEntityInterface {
    $entity_type_id = (string) ($context->known['entity_type'] ?? '');
    $bundle = (string) ($context->known['bundle'] ?? '');
    $id = $context->known['id'] ?? NULL;
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if ($id !== NULL) {
      $entity = $storage->load($id);
      return $entity instanceof ContentEntityInterface ? $entity : NULL;
    }
    // Hole: field access takes items, so a field of an entity not yet
    // made is asked about on a throwaway one. See docs/entities.md#holes.
    if (!isset($this->stubs[$entity_type_id . ':' . $bundle])) {
      $bundle_key = (string) $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle');
      $stub = $storage->create($bundle_key === '' ? [] : [$bundle_key => $bundle]);
      assert($stub instanceof ContentEntityInterface);
      $this->stubs[$entity_type_id . ':' . $bundle] = $stub;
    }
    return $this->stubs[$entity_type_id . ':' . $bundle];
  }

}
