<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * An alter that answers access for a resource or structure it does not own.
 *
 * Optional, beside AltersInputsInterface. What makes a class an alter is
 * #[Alters] on it; implementing this adds the alter's answer to the
 * answers of the resource or structure it names, asked after the owner's
 * own (its operation's permission, then its access class), in the frame
 * where that thing is built, wherever it appears: at the top, as a child,
 * as a slot's variant, mixed in, at every entry of keys from the site.
 *
 * The answers combine as two answers about the same keys always have:
 * the stricter stands (AccessAnswer::andIf()). So an alter may lock or
 * omit the keys of what it alters, its own mounted keys among them, and
 * can never open what the owner or an earlier alter locked or omitted.
 * Each answer's kind is read from its own cacheability, as any answer's
 * is: a static one is stated in the full schema and the tool listing for
 * the account that asks, a runtime one is marked there and settled once
 * the thing is known. The operation's permission stays the owner's; an
 * alter that wants a further permission answers locked or omitted for an
 * account without it, with the `user.permissions` context, which reads
 * static.
 *
 * The same method as OperationAccessInterface::access(), on the alter
 * itself: an alter is an autowired service, so it may hold what it reads
 * the thing with.
 *
 * Decision: see
 * docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
 */
interface AltersAccessInterface {

  /**
   * Answers what an account may do with the keys of what this alter names.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the altered resource or structure is being asked for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to answer for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Resource\AccessAnswer
   *   Open, locked or omitted. Core's result is read as AccessAnswer reads
   *   it at a child, wherever the alter answers: allowed and neutral are
   *   open, since an alter only narrows, and forbidden is omitted.
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface|AccessAnswer;

}
