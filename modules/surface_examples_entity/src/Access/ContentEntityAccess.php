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
use Drupal\surface_examples_entity\FieldItemDerivation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity access, as a content entity resource's access.
 *
 * Create access for the bundle when the context creates, update access
 * for the entity when it knows one: exactly what core's own form route
 * asks, so the resource never lets anyone write what core's form would
 * not. Each field then answers for itself (FieldItemAccess).
 *
 * An entity type's resource creates with no bundle known: the bundle is a
 * value the caller chooses, held to those the account may create by its
 * key (SurfaceCreatableBundle), so create access for it is asked once it
 * is chosen, and never for an empty bundle. Here the operation is open to
 * an account that may create at least one of the entity type's bundles,
 * and forbidden to any other.
 */
final class ContentEntityAccess implements OperationAccessInterface, ContainerInjectionInterface {

  /**
   * Constructs a ContentEntityAccess.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   Which bundles have a form display, and which of them may be created.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FieldItemDerivation $derivation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('surface_examples_entity.field_item_derivation'));
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
    if (($context->creates || $id === NULL) && !isset($context->known['bundle'])) {
      [$creatable, $cacheability] = $this->derivation->creatableBundles($entity_type_id, $account);
      $answer = $creatable === [] ? AccessResult::forbidden(sprintf('The account may create no %s of a bundle with a form display.', $entity_type_id)) : AccessResult::allowed();
      return $answer->addCacheableDependency($cacheability);
    }
    if ($context->creates || $id === NULL) {
      return $this->entityTypeManager->getAccessControlHandler($entity_type_id)->createAccess((string) $context->known['bundle'], $account, [], TRUE);
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if ($entity === NULL) {
      return AccessResult::forbidden(sprintf('There is no %s %s.', $entity_type_id, $id));
    }
    return $entity->access('update', $account, TRUE);
  }

}
