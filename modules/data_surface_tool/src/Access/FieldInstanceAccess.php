<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceContext;

/**
 * What the permission cannot say: a locked storage may not be edited.
 */
final class FieldInstanceAccess implements SurfaceAccessInterface {

  /**
   * Constructs a FieldInstanceAccess.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface {
    $known = $context->known;
    if (!isset($known['entity_type_id'], $known['field_name'])) {
      return AccessResult::allowed();
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load($known['entity_type_id'] . '.' . $known['field_name']);
    $locked = $storage !== NULL && $storage->isLocked();
    // Allowed rather than neutral when the storage is open: the
    // situation's permission is joined to this answer with andIf(), and
    // allowed and neutral is neutral.
    $result = $locked ? AccessResult::forbidden('The field storage is locked.') : AccessResult::allowed();
    return $storage === NULL ? $result : $result->addCacheableDependency($storage);
  }

}
