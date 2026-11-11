<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * The site's account switcher, which records the current user per switch.
 *
 * What OperationRunnerTest hands a runner to show which account a call
 * runs as and that the current user is back afterwards: `said` lists the
 * current user's id after each switch, as `to <id>` and `back <id>`.
 */
final class RecordingAccountSwitcher implements AccountSwitcherInterface {

  /**
   * The current user's id after each switch.
   *
   * @var list<string>
   */
  public array $said = [];

  /**
   * Constructs a RecordingAccountSwitcher.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The site's account switcher.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, read after each switch.
   */
  public function __construct(
    protected readonly AccountSwitcherInterface $switcher,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function switchTo(AccountInterface $account): static {
    $this->switcher->switchTo($account);
    $this->said[] = 'to ' . $this->currentUser->id();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function switchBack(): static {
    $this->switcher->switchBack();
    $this->said[] = 'back ' . $this->currentUser->id();
    return $this;
  }

}
