<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * What a row reads from a schema.
 *
 * @internal
 */
final class ReadBack {

  /**
   * Constructs a ReadBack.
   *
   * @param string|null $constraint
   *   The constraint plugin id; NULL when the keywords state nothing to
   *   declare (`uniqueItems: false`), or only what is kept.
   * @param array $options
   *   Its options.
   * @param string[] $consumed
   *   The keywords the constraint states.
   * @param array $kept
   *   Keywords of the row the reader keeps in the JsonSchema constraint,
   *   by keyword.
   * @param string|null $note
   *   What the reader notes at the kept keywords, if anything.
   */
  public function __construct(
    public readonly ?string $constraint,
    public readonly array $options = [],
    public readonly array $consumed = [],
    public readonly array $kept = [],
    public readonly ?string $note = NULL,
  ) {}

}
