<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Lock\LockBackendInterface;

/**
 * A lock that records what it is asked, and may be found taken.
 *
 * For the tool deriver's rebuild: whether it takes the lock, and what a
 * request does when another holds it.
 */
final class RecordingLock implements LockBackendInterface {

  /**
   * What was asked, in order: the method and the lock's name.
   *
   * @var array<int, array{0: string, 1: string}>
   */
  public array $asked = [];

  /**
   * Whether acquire() finds the lock held by another request.
   */
  public bool $taken = FALSE;

  /**
   * What runs while a request waits: the other request's work.
   *
   * @var (\Closure(): void)|null
   */
  public ?\Closure $whileWaiting = NULL;

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0): bool {
    $this->asked[] = ['acquire', $name];
    return !$this->taken;
  }

  /**
   * {@inheritdoc}
   */
  public function lockMayBeAvailable($name): bool {
    return !$this->taken;
  }

  /**
   * {@inheritdoc}
   */
  public function wait($name, $delay = 30): bool {
    $this->asked[] = ['wait', $name];
    if ($this->whileWaiting !== NULL) {
      ($this->whileWaiting)();
    }
    $this->taken = FALSE;
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function release($name): void {
    $this->asked[] = ['release', $name];
  }

  /**
   * {@inheritdoc}
   */
  public function releaseAll($lockId = NULL): void {}

  /**
   * {@inheritdoc}
   */
  public function getLockId(): string {
    return 'test';
  }

}
