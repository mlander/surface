<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity access, as a content entity resource's access.
 *
 * Create access for the bundle when the context creates, update access
 * for the entity when it knows one: exactly what core's own form route
 * asks, so the resource never lets anyone write what core's form would
 * not. Each field then answers for itself (FieldItemAccess).
 */
final class ContentEntityAccess implements OperationAccessInterface, ContainerInjectionInterface {

  /**
   * Constructs a ContentEntityAccess.
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
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    $entity_type_id = (string) ($context->known['entity_type'] ?? '');
    $id = $context->known['id'] ?? NULL;
    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return AccessResult::forbidden('The context names no entity type.');
    }
    if ($context->creates || $id === NULL) {
      return $this->entityTypeManager->getAccessControlHandler($entity_type_id)->createAccess((string) ($context->known['bundle'] ?? ''), $account, [], TRUE);
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if ($entity === NULL) {
      return AccessResult::forbidden(sprintf('There is no %s %s.', $entity_type_id, $id));
    }
    return $entity->access('update', $account, TRUE);
  }

}
