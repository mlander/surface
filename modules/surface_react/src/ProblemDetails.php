<?php

declare(strict_types=1);

namespace Drupal\surface_react;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface_react\Controller\ResourceApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Writes an API error as problem details (RFC 9457).
 *
 * `type`, `title`, `status` and `detail`, then the extension members the
 * problem carries. A refusal (422) carries `errors`, each `{path, code,
 * message}` with `path` a JSON Pointer, and `notes` and `stale` as the one
 * result has them; a conflict (412) its one error, code `conflict`; a
 * contract 2 path (410) `moved`, the method and path that replace it.
 * Every other status is `about:blank`, its title the reason phrase.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ProblemDetails {

  /**
   * The media type of a problem details document.
   */
  public const MEDIA_TYPE = 'application/problem+json';

  /**
   * The type of a problem the status says all of.
   */
  public const BLANK = 'about:blank';

  /**
   * Where the module's own problem types are described.
   */
  public const TYPES = 'https://git.drupalcode.org/project/surface/-/blob/1.0.x/docs/served-contract.md#';

  /**
   * The values were refused: 422.
   */
  public const REFUSED = self::TYPES . 'refused';

  /**
   * What is stored changed since the ETag sent was served: 412.
   */
  public const CONFLICT = self::TYPES . 'conflict';

  /**
   * A contract 2 path: 410.
   */
  public const GONE = self::TYPES . 'gone';

  /**
   * Answers an HTTP exception as problem details.
   *
   * @param \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception
   *   The exception; a ProblemException carries its own type, title and
   *   extension members.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response, `application/problem+json`, with the exception's
   *   headers and the `Contract-Version` header.
   */
  public static function fromException(HttpExceptionInterface $exception): JsonResponse {
    if ($exception instanceof ProblemException) {
      return static::response($exception->getStatusCode(), $exception->getMessage(), $exception->extensions, $exception->type, $exception->title, $exception->getHeaders());
    }
    return static::response($exception->getStatusCode(), $exception->getMessage(), [], self::BLANK, NULL, $exception->getHeaders());
  }

  /**
   * Writes a problem details response.
   *
   * @param int $status
   *   The HTTP status.
   * @param string $detail
   *   What happened to this request; empty writes no `detail`.
   * @param array<string, mixed> $extensions
   *   Extension members.
   * @param string $type
   *   The problem type's URI.
   * @param string|null $title
   *   The title; NULL for the status's reason phrase.
   * @param array<string, string|string[]> $headers
   *   More response headers.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public static function response(int $status, string $detail, array $extensions = [], string $type = self::BLANK, ?string $title = NULL, array $headers = []): JsonResponse {
    $body = [
      'type' => $type,
      'title' => $title ?? (Response::$statusTexts[$status] ?? 'Error'),
      'status' => $status,
    ];
    if ($detail !== '') {
      $body['detail'] = $detail;
    }
    $response = new JsonResponse($body + $extensions, $status, $headers);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set('Content-Type', self::MEDIA_TYPE);
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    return $response;
  }

}
