<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Cache;
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
 * What it depends on also says which of two kinds it is, read and never
 * declared (kindOf()): static, decidable from the account alone, when it
 * varies by nothing but the account's permissions or roles; runtime,
 * decided once the subject is known, when it varies by anything else (a
 * cache tag, the `user` context, a max-age), or when an access class
 * returned it with nothing at all, since that promises nothing. The full
 * schema and a tool's listing state a static answer for the account that
 * asks and mark a runtime one.
 *
 * Decision: see docs/decisions.md#access-has-three-answers.
 * Decision: see
 * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
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
   * Decidable from the account alone: stated ahead of time.
   */
  public const STATIC = 'static';

  /**
   * Decided once the subject is known: marked ahead of time.
   */
  public const RUNTIME = 'runtime';

  /**
   * The cache contexts a static answer may vary by: the account's own.
   *
   * `user.roles:<role>` is one of them too, read by its prefix.
   */
  public const ACCOUNT_CONTEXTS = ['user.permissions', 'user.roles'];

  /**
   * Whether an access class returned this with no cacheability at all.
   *
   * Such an answer promised nothing about what it read, so it is runtime
   * whatever is added to it afterwards.
   */
  protected bool $promisedNothing = FALSE;

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
   *   result's cacheability; an answer of this type as it is, copied.
   *   One that carried no cacheability at all is remembered as having
   *   promised nothing, which kindOf() reads as runtime.
   */
  public static function fromAnswer(AccessResultInterface|self $answer): self {
    $read = match (TRUE) {
      $answer instanceof self => new self($answer->answer, $answer->reason),
      $answer->isForbidden() => self::omitted(self::reasonOf($answer)),
      default => self::open(),
    };
    if ($answer instanceof self) {
      $read->promisedNothing = $answer->promisedNothing;
    }
    if ($answer instanceof CacheableDependencyInterface) {
      $read->addCacheableDependency($answer);
    }
    // An answer an access class gave with no cacheability at all promised
    // nothing about what it read: runtime.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $read->promisedNothing = $read->promisedNothing || !static::carriesAnything($answer);
    return $read;
  }

  /**
   * Reads which kind an answer is from what it depends on.
   *
   * Static when it varies by the account's permissions or roles and by
   * nothing else: every cache context is `user.permissions`, `user.roles`
   * or `user.roles:<role>`, it carries no cache tag, and its max-age is
   * permanent. Anything else is runtime: an entity's or a config object's
   * tag, the `user` context, a max-age of 0 for a State read. Core's
   * access results and these answers are read alike; an answer an access
   * class returned with nothing at all is read by fromAnswer(), which
   * remembers that it promised nothing.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $answer
   *   The answer.
   *
   * @return string
   *   self::STATIC or self::RUNTIME.
   */
  public static function kindOf(CacheableDependencyInterface $answer): string {
    if ($answer instanceof self && $answer->promisedNothing) {
      return self::RUNTIME;
    }
    if ($answer->getCacheTags() !== [] || $answer->getCacheMaxAge() !== Cache::PERMANENT) {
      return self::RUNTIME;
    }
    foreach ($answer->getCacheContexts() as $context) {
      if (!in_array($context, self::ACCOUNT_CONTEXTS, TRUE) && !str_starts_with($context, 'user.roles:')) {
        return self::RUNTIME;
      }
    }
    return self::STATIC;
  }

  /**
   * Gets which kind this answer is: static or runtime.
   *
   * @return string
   *   self::STATIC or self::RUNTIME.
   */
  public function kind(): string {
    return static::kindOf($this);
  }

  /**
   * Answers whether this answer is decidable from the account alone.
   *
   * @return bool
   *   TRUE for static.
   */
  public function isStatic(): bool {
    return $this->kind() === self::STATIC;
  }

  /**
   * Answers whether an answer carries any cacheability at all.
   *
   * @param \Drupal\Core\Access\AccessResultInterface|self $answer
   *   The answer.
   *
   * @return bool
   *   FALSE for one with no cache context, no tag and a permanent max-age.
   */
  protected static function carriesAnything(AccessResultInterface|self $answer): bool {
    if (!$answer instanceof CacheableDependencyInterface) {
      return FALSE;
    }
    return $answer->getCacheContexts() !== [] || $answer->getCacheTags() !== [] || $answer->getCacheMaxAge() !== Cache::PERMANENT;
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
  public function getAnswer(): string {
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
   * The combination depends on both, and is static only when both are,
   * except where one omits the keys statically: nothing narrower exists,
   * so what the other read cannot change it, and it depends on that one
   * alone, as core's andIf() reads a forbidden result.
   *
   * @param self $other
   *   The other answer.
   *
   * @return self
   *   A new answer, the narrower of the two, depending on both, or on the
   *   static omission alone where one settles it.
   */
  public function andIf(self $other): self {
    $settling = array_values(array_filter([$this, $other], static fn (self $side): bool => $side->isOmitted() && $side->isStatic()));
    $narrower = $settling[0] ?? (self::ORDER[$other->answer] > self::ORDER[$this->answer] ? $other : $this);
    $combined = new self($narrower->answer, $narrower->reason);
    foreach ($settling === [] ? [$this, $other] : $settling as $side) {
      $combined->addCacheableDependency($side);
      $combined->promisedNothing = $combined->promisedNothing || $side->promisedNothing;
    }
    return $combined;
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
