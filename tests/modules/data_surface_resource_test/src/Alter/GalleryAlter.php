<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\Gallery\GalleryResource;

/**
 * Another module's answer for the gallery, which names no access class.
 *
 * In the curating operation only. Static: an account that may inspect the
 * gallery finds it locked, at the top locked whole. Runtime: a closed
 * gallery, a State flag, is left out, which at the top refuses the
 * caller; set aside for the account alone, it marks every key.
 */
#[Alters(GalleryResource::class, operations: ['curate'])]
final class GalleryAlter implements OperationAccessInterface {

  /**
   * The State key that closes the gallery.
   */
  public const CLOSED = 'data_surface_resource_test.gallery_closed';

  /**
   * How often it was asked, so a test sees a static answer kept.
   */
  public static int $calls = 0;

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    self::$calls++;
    if ($account->hasPermission('inspect gallery')) {
      return AccessAnswer::locked('The gallery may be inspected, not changed.')->addCacheContexts(['user.permissions']);
    }
    $answer = $this->state->get(self::CLOSED, FALSE) ? AccessAnswer::omitted('The gallery is closed.') : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions'])->mergeCacheMaxAge(0);
  }

}
