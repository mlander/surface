<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * What an account may do with a set of keys: change, see, or neither.
 *
 * Core's AccessResultInterface answers yes or no. Field access has a
 * middle, a field one may see and not edit, so access here has three
 * answers:
 *   - open: the keys may be changed;
 *   - locked: the keys are shown and cannot be changed, every one of
 *     them locked the way a known identity key is;
 *   - omitted: the keys are not there at all, in either direction.
 *
 * An access class may return one of these from access(), or keep
 * returning an AccessResultInterface, which is read as one: allowed is
 * open; forbidden is omitted at a child and denied at the top; neutral
 * is no opinion, which leaves a child open (its parent's access already
 * let the caller in) and denies at the top (an operation's caller reads
 * no opinion as a refusal). Like an access result, it carries what the
 * answer depends on, which reaches the data surface and everything
 * emitted from it.
 *
 * Decision: see docs/decisions.md#access-has-three-answers.
 */
final class AccessAnswer implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * The keys may be changed.
   */
  protected const OPEN = 'open';

  /**
   * The keys are shown and cannot be changed.
   */
  protected const LOCKED = 'locked';

  /**
   * The keys are not there.
   */
  protected const OMITTED = 'omitted';

  /**
   * How narrow each answer is: a combination keeps the narrower.
   */
  protected const ORDER = [self::OPEN => 0, self::LOCKED => 1, self::OMITTED => 2];

  /**
   * Constructs an AccessAnswer.
   *
   * @param string $answer
   *   One of the three answers.
   * @param string|null $reason
   *   Why, for a message or a test; NULL when there is nothing to say.
   */
  protected function __construct(
    protected readonly string $answer,
    protected readonly ?string $reason = NULL,
  ) {}

  /**
   * The keys may be changed.
   *
   * @return self
   *   The answer.
   */
  public static function open(): self {
    return new self(self::OPEN);
  }

  /**
   * The keys are shown and cannot be changed.
   *
   * @param string|null $reason
   *   Why.
   *
   * @return self
   *   The answer.
   */
  public static function locked(?string $reason = NULL): self {
    return new self(self::LOCKED, $reason);
  }

  /**
   * The keys are not there.
   *
   * @param string|null $reason
   *   Why.
   *
   * @return self
   *   The answer.
   */
  public static function omitted(?string $reason = NULL): self {
    return new self(self::OMITTED, $reason);
  }

  /**
   * Reads what an access class answered, as it is read at a child.
   *
   * @param \Drupal\Core\Access\AccessResultInterface|self $answer
   *   The answer: one of these, or core's.
   *
   * @return self
   *   Open for allowed and for neutral, omitted for forbidden, with the
   *   result's cacheability; an answer of this type as it is.
   */
  public static function fromAnswer(AccessResultInterface|self $answer): self {
    if ($answer instanceof self) {
      return $answer;
    }
    $read = $answer->isForbidden() ? self::omitted(self::reasonOf($answer)) : self::open();
    if ($answer instanceof CacheableDependencyInterface) {
      $read->addCacheableDependency($answer);
    }
    return $read;
  }

  /**
   * Answers whether the keys may be changed.
   *
   * @return bool
   *   TRUE for open.
   */
  public function isOpen(): bool {
    return $this->answer === self::OPEN;
  }

  /**
   * Answers whether the keys are shown and fixed.
   *
   * @return bool
   *   TRUE for locked.
   */
  public function isLocked(): bool {
    return $this->answer === self::LOCKED;
  }

  /**
   * Answers whether the keys are left out.
   *
   * @return bool
   *   TRUE for omitted.
   */
  public function isOmitted(): bool {
    return $this->answer === self::OMITTED;
  }

  /**
   * Gets the answer's name: open, locked or omitted.
   *
   * @return string
   *   The name.
   */
  public function answer(): string {
    return $this->answer;
  }

  /**
   * Gets why, when the answer says.
   *
   * @return string|null
   *   The reason.
   */
  public function getReason(): ?string {
    return $this->reason;
  }

  /**
   * Combines two answers about the same keys: the narrower stands.
   *
   * @param self $other
   *   The other answer.
   *
   * @return self
   *   A new answer, the narrower of the two, depending on both.
   */
  public function andIf(self $other): self {
    $narrower = self::ORDER[$other->answer] > self::ORDER[$this->answer] ? $other : $this;
    $combined = new self($narrower->answer, $narrower->reason);
    return $combined->addCacheableDependency($this)->addCacheableDependency($other);
  }

  /**
   * Reads the reason off core's answer, when it has one.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   The answer.
   *
   * @return string|null
   *   The reason.
   */
  protected static function reasonOf(AccessResultInterface $result): ?string {
    return $result instanceof AccessResultReasonInterface ? ($result->getReason() ?: NULL) : NULL;
  }

}
