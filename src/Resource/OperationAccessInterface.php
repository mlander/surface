<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The part of access that depends on the subject.
 *
 * Named on #[Resource(access:)] or #[Structure(access:)]. The static
 * part, a permission, is on each #[Operation] and is checked first; this
 * runs only when it passes. Registered as an autowired service when the
 * class naming it is discovered, the way a hook class is, so it may hold
 * services.
 * Another module's alter may answer as well, after this class
 * (AltersAccessInterface): the stricter answer stands, so an alter
 * narrows and never opens what this answered.
 *
 * The same class answers at both levels. At the top, for an operation
 * asked on its own, it decides whether the caller gets in at all. At a
 * child invoked through one of its own operations, it decides what the
 * caller may do with that child's keys: change them, only see them, or
 * not see them (AccessAnswer). A child that inherits its parent's
 * context, invoking no operation of its own, is asked as before, and may
 * only refuse the whole parent.
 */
interface OperationAccessInterface {

  /**
   * Answers whether an account may submit it in this context.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to answer for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Resource\AccessAnswer
   *   The access answer. Core's result is read as AccessAnswer reads it:
   *   allowed is open, forbidden is omitted at a child and denied at the
   *   top. AccessAnswer::locked() is the one answer core's cannot say. An
   *   implementation that declares the narrower AccessResultInterface
   *   return type, as every one written before the third answer does,
   *   still implements this.
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface|AccessAnswer;

}
