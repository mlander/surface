<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Locks the title of a node whose title starts with "Locked".
 *
 * Another module's answer through core's field access: whichever node it
 * is asked about, its answer depends on that node, so it carries it.
 */
final class GateTestHooks {

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $entity = $items?->getEntity();
    if ($operation !== 'edit' || $field_definition->getName() !== 'title' || $entity === NULL || $entity->getEntityTypeId() !== 'node') {
      return AccessResult::neutral();
    }
    return str_starts_with((string) $entity->label(), 'Locked')
      ? AccessResult::forbidden('Its title is locked.')->addCacheableDependency($entity)
      : AccessResult::neutral()->addCacheableDependency($entity);
  }

}
