<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\node\NodeTypeInterface;

/**
 * What the permission cannot say: the node type entity's own answer.
 *
 * A content type is a content type however it is edited, so this module
 * never grants more than core's own content type form grants: creating
 * asks the node type access handler whether one may be created, editing
 * asks the content type the context names whether it may be updated. The
 * situation's permission, this module's own, is checked first; both have
 * to allow, so neither alone opens anything.
 */
final class NodeTypeAccess implements SurfaceAccessInterface {

  /**
   * Constructs a NodeTypeAccess.
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
    if ($context->creates) {
      return $this->entityTypeManager->getAccessControlHandler('node_type')
        ->createAccess(NULL, $account, [], TRUE);
    }
    $id = $context->known['type'] ?? NULL;
    $type = is_string($id) ? $this->entityTypeManager->getStorage('node_type')->load($id) : NULL;
    if (!$type instanceof NodeTypeInterface) {
      // Refused, and cacheable until a content type is created, so the
      // answer stops being no the moment it exists.
      return AccessResult::forbidden(sprintf('There is no "%s" content type to configure.', is_scalar($id) ? (string) $id : ''))
        ->addCacheTags(['config:node_type_list']);
    }
    return $type->access('update', $account, TRUE);
  }

}
