<?php

declare(strict_types=1);

namespace Drupal\surface_entity_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Field access and an entity constraint, for the derived resources' tests.
 */
final class EntityTestHooks {

  /**
   * The field nobody without the bypass permission may change.
   */
  public const LOCKED = 'field_locked';

  /**
   * The field nobody without the bypass permission may see.
   */
  public const HIDDEN = 'field_hidden';

  /**
   * The permission that opens both.
   */
  public const BYPASS = 'bypass surface entity test field access';

  /**
   * Locks one field and hides another, unless the account may bypass.
   */
  #[Hook('entity_field_access')]
  public function fieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $name = $field_definition->getName();
    if (!in_array($name, [self::LOCKED, self::HIDDEN], TRUE)) {
      return AccessResult::neutral();
    }
    if ($account->hasPermission(self::BYPASS)) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    $refused = $name === self::HIDDEN || $operation === 'edit';
    return AccessResult::forbiddenIf($refused)->cachePerPermissions();
  }

  /**
   * Adds an entity-level constraint to nodes.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['node']) && $entity_types['node'] instanceof EntityTypeInterface) {
      $entity_types['node']->addConstraint('SurfaceEntityTestTitle');
    }
  }

}
