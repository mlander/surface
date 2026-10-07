<?php

declare(strict_types=1);

namespace Drupal\field\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface_sketch\Surface\SurfaceAccessInterface;
use Drupal\surface_sketch\Surface\SurfaceContext;

/**
 * What the permission cannot say: a locked storage may not be edited.
 */
final class FieldInstanceAccess implements SurfaceAccessInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypes) {}

  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface {
    $known = $context->known;
    if (!isset($known['entity_type_id'], $known['field_name'])) {
      return AccessResult::allowed();
    }
    $storage = $this->entityTypes->getStorage('field_storage_config')
      ->load($known['entity_type_id'] . '.' . $known['field_name']);
    return AccessResult::forbiddenIf($storage?->isLocked() ?? FALSE, 'The field storage is locked.')
      ->addCacheableDependency($storage);
  }

}
