<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\SurfaceAccess;

/**
 * Why the runner refused a call, as it was: for the server alone.
 *
 * Refusal::actual() answers one. It holds the true category, code,
 * reason, cacheability and access answer, for the server's own decisions
 * (a form telling "the stage is gone" from "the stage is refused") and
 * its logs. It must never be rendered to the account the call ran as:
 * what that account may hear is the Refusal itself.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
final readonly class RefusalDetail {

  /**
   * Constructs a RefusalDetail.
   *
   * @param \Drupal\surface\Operation\RefusalCategory $category
   *   Why the call was refused.
   * @param string $code
   *   The result code (Violation): `not_found`, `permission`, `access` or
   *   `does_not_apply`.
   * @param string $reason
   *   The full reason.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the answer varies by.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The access answer that refused, for a refusal by access; NULL
   *   otherwise.
   */
  public function __construct(
    public RefusalCategory $category,
    public string $code,
    public string $reason,
    public CacheableMetadata $cacheability,
    public ?AccessResultInterface $access = NULL,
  ) {}

  /**
   * Gets the refusal as one violation of the whole call.
   *
   * Its path is empty and its code is this one's. A refusal by access or
   * by the permission reads as the pipeline's own refusal of a run reads
   * (SurfaceAccess::message()), so a door gives the same message whether
   * the runner or the pipeline refused.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The violation.
   */
  public function violation(): Violation {
    $message = in_array($this->code, [Violation::ACCESS, Violation::PERMISSION], TRUE)
      ? SurfaceAccess::message(AccessResult::forbidden($this->reason))
      : new FormattableMarkup('@reason', ['@reason' => $this->reason]);
    return new Violation('', '', $message, $this->code);
  }

}
