<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The part of access that depends on the subject.
 *
 * Named on #[Surface(access:)]. The static part, a permission, is on
 * each #[Situation] and is checked first; this runs only when it
 * passes. Found like a hook class, so it may hold services. Alters
 * never touch access.
 */
interface SurfaceAccessInterface {

  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface;

}
