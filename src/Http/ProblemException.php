<?php

declare(strict_types=1);

namespace Drupal\surface\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An HTTP error the API answers as problem details (RFC 9457).
 *
 * Thrown where a request is read (ServedPaths, the controller) and
 * answered by ProblemDetailsSubscriber, which writes its type, title,
 * status, detail and extension members as `application/problem+json`.
 * Any other HTTP exception on an API path is answered the same way, with
 * `about:blank` as its type.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ProblemException extends HttpException {

  /**
   * Constructs a ProblemException.
   *
   * @param int $status
   *   The HTTP status.
   * @param string $detail
   *   What happened to this request, as plain text.
   * @param array<string, mixed> $extensions
   *   Extension members, written after the standard ones: `errors`,
   *   `notes`, `stale`, `moved`.
   * @param string $type
   *   The problem type's URI; `about:blank` when the status says it all.
   * @param string|null $title
   *   The problem type's title; NULL for the status's reason phrase.
   * @param array<string, string> $headers
   *   Response headers: `Allow` on a 405.
   */
  public function __construct(
    int $status,
    string $detail,
    public readonly array $extensions = [],
    public readonly string $type = ProblemDetails::BLANK,
    public readonly ?string $title = NULL,
    array $headers = [],
  ) {
    parent::__construct($status, $detail, NULL, $headers);
  }

}
