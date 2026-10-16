<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The part of access that depends on the subject.
 *
 * Named on #[Surface(access:)]. The static part, a permission, is on
 * each #[Situation] and is checked first; this runs only when it
 * passes. Registered as an autowired service when the surface naming it
 * is discovered, the way a hook class is, so it may hold services.
 * Alters never touch access.
 *
 * The same class answers at both levels. At the top, for a situation
 * asked on its own, it decides whether the caller gets in at all. At a
 * child invoked through one of its own situations, it decides what the
 * caller may do with that child's keys: change them, only see them, or
 * not see them (SurfaceAccess). A child that inherits its parent's
 * context, invoking no situation of its own, is asked as before, and may
 * only refuse the whole parent.
 */
interface SurfaceAccessInterface {

  /**
   * Answers whether an account may submit the surface in this context.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where the surface is being asked for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to answer for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Surface\SurfaceAccess
   *   The access answer. Core's result is read as SurfaceAccess reads it:
   *   allowed is open, forbidden is omitted at a child and denied at the
   *   top. SurfaceAccess::locked() is the one answer core's cannot say. An
   *   implementation that declares the narrower AccessResultInterface
   *   return type, as every one written before the third answer does,
   *   still implements this.
   */
  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface|SurfaceAccess;

}
