<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples\Entity\EventVenue;
use Drupal\surface_examples\Resource\VenueResource;
use Drupal\surface_examples\Storage\VenueStorage;

/**
 * Example 15: a disabled venue is for those who administer the venues.
 *
 * Asked after the operation's permission, for the venue the context
 * names. An account that administers the venues may do what its
 * permission lets it with any of them, which its permissions alone
 * decide. Any other account is refused a disabled venue, which only the
 * venue can say: so a listing, which asks this for each venue, leaves the
 * disabled ones out for an account that only views them.
 */
final class VenueAccess implements OperationAccessInterface {

  /**
   * Constructs the access class, which the container builds as a service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the venue the context names.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    $id = $context->known['id'] ?? NULL;
    if ($account->hasPermission(VenueResource::ADMINISTER) || !is_string($id) || $id === '') {
      // Static: the account alone, or no venue to ask about.
      return AccessResult::allowed()->cachePerPermissions();
    }
    $venue = $this->entityTypeManager->getStorage(VenueStorage::ENTITY_TYPE_ID)->load($id);
    if (!$venue instanceof EventVenue) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheTags(['config:' . VenueStorage::ENTITY_TYPE_ID . '_list']);
    }
    // Runtime: what is stored, under the venue's own tag.
    $answer = $venue->status() ? AccessResult::allowed() : AccessResult::forbidden('The venue is disabled, and only an account that administers the venues reads it.');
    return $answer->cachePerPermissions()->addCacheableDependency($venue);
  }

}
