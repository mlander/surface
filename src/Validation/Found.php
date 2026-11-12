<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

/**
 * One refusal the dialect's validator found, said.
 *
 * @internal
 */
final class Found {

  /**
   * Constructs a Found.
   *
   * @param string $path
   *   Where it is, as a dotted path from the top; '' for the top.
   * @param string $code
   *   The dialect's code (`maxLength`, `check.drupal:SurfaceUnique.taken`),
   *   or `unchecked`.
   * @param array<string, mixed> $params
   *   Its params.
   * @param string|\Stringable $message
   *   What to say about it, unrendered.
   */
  public function __construct(
    public readonly string $path,
    public readonly string $code,
    public readonly array $params,
    public readonly string|\Stringable $message,
  ) {
  }

}
