<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\surface\Pipeline\Violation;

/**
 * Why the runner refused a call, as the account may hear it.
 *
 * Every public field and violation() is safe to show the account the
 * call ran as. Past the operation's permission, an account that does not
 * hold it (for an operation that declares none, every account) hears
 * the same refusal whatever refused: category Access, code `access`, the
 * reason WITHHELD, max-age 0. So it cannot tell an id that names nothing
 * from one it may not touch, or a stage that is gone from another
 * account's. A stage that is gone and one that is another account's are
 * that same refusal for every account, the permission held or not.
 * Everything else is told in full: an unknown resource or operation,
 * the permission itself, a stage that does not apply to the operation,
 * and, to an account that holds the permission, what the arguments name.
 *
 * The true category, code and reason are kept beside it, under actual(),
 * for the server's own decisions and logs.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
final readonly class Refusal {

  /**
   * The one reason an account hears when the true one is withheld.
   */
  public const WITHHELD = 'The operation cannot be run by this account with these parameters.';

  /**
   * Constructs a Refusal.
   *
   * @param \Drupal\surface\Operation\RefusalCategory $category
   *   Why the call was refused, as the account may hear it.
   * @param string $code
   *   The result code a door files it under (Violation): `not_found`,
   *   `permission`, `access` or `does_not_apply`.
   * @param string $reason
   *   The reason the account may hear: the full one, or WITHHELD.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the answer varies by, for a door that caches it.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The access answer a door may hand on, for a refusal by access;
   *   NULL otherwise. Its reason is this refusal's.
   * @param \Drupal\surface\Operation\RefusalDetail $actual
   *   The refusal as it was.
   */
  private function __construct(
    public RefusalCategory $category,
    public string $code,
    public string $reason,
    public CacheableMetadata $cacheability,
    public ?AccessResultInterface $access,
    private RefusalDetail $actual,
  ) {}

  /**
   * Builds a refusal every account may hear in full.
   *
   * @param \Drupal\surface\Operation\RefusalDetail $actual
   *   The refusal as it was, which is what is shown.
   *
   * @return self
   *   The refusal.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function told(RefusalDetail $actual): self {
    return new self($actual->category, $actual->code, $actual->reason, $actual->cacheability, $actual->access, $actual);
  }

  /**
   * Builds a refusal whose true reason the account may not hear.
   *
   * @param \Drupal\surface\Operation\RefusalDetail $actual
   *   The refusal as it was, kept for actual().
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What every withheld refusal of this operation varies by: the
   *   permission's answer, never what refused. Max-age 0 is set on it.
   *
   * @return self
   *   The refusal.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function withheld(RefusalDetail $actual, CacheableMetadata $cacheability): self {
    $shown = (new CacheableMetadata())->addCacheableDependency($cacheability)->setCacheMaxAge(0);
    $access = AccessResult::forbidden(self::WITHHELD)->addCacheableDependency($shown);
    return new self(RefusalCategory::Access, Violation::ACCESS, self::WITHHELD, $shown, $access, $actual);
  }

  /**
   * Gets the refusal as it was, for the server alone.
   *
   * The true category, code, reason and cacheability, for the server's
   * own decisions (which page to send the account to, whether a stage is
   * gone) and for its logs. Never render it to the account the call ran
   * as, nor put it in a response, a message or a page it can read: the
   * Refusal's own fields are what that account may hear.
   *
   * @return \Drupal\surface\Operation\RefusalDetail
   *   The refusal as it was.
   */
  public function actual(): RefusalDetail {
    return $this->actual;
  }

  /**
   * Gets the refusal, as the account may hear it, as one violation.
   *
   * Its path is empty and its code is this refusal's. A refusal by
   * access reads as the pipeline's own refusal of a run reads
   * (SurfaceAccess::message()), so a door gives the same message whether
   * the runner or the pipeline refused.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The violation.
   */
  public function violation(): Violation {
    return (new RefusalDetail($this->category, $this->code, $this->reason, $this->cacheability, $this->access))->violation();
  }

}
