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
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   */
  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface;

}
