<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: how it is drawn, the arrangement of describe().
 *
 * Called right after the key is declared. `group:` names a #[Group] on
 * the class.
 *
 * @code
 * #[Describe(group: 'details', suffix: 'EUR')]
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::describe()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Describe {

  /**
   * Constructs a Describe attribute.
   *
   * @param string|null $group
   *   The id of the #[Group] the key is drawn in; NULL for none.
   * @param int|null $weight
   *   Its weight among its frame's keys, lighter first; NULL for none.
   * @param string|\Stringable|null $placeholder
   *   What an empty input shows, written as #[Input]'s label is.
   * @param string|\Stringable|null $prefix
   *   A text drawn before the input.
   * @param string|\Stringable|null $suffix
   *   A text drawn after the input.
   * @param string|\Stringable|null $help
   *   A help text drawn after the key's description.
   */
  public function __construct(
    public readonly ?string $group = NULL,
    public readonly ?int $weight = NULL,
    public readonly string|\Stringable|null $placeholder = NULL,
    public readonly string|\Stringable|null $prefix = NULL,
    public readonly string|\Stringable|null $suffix = NULL,
    public readonly string|\Stringable|null $help = NULL,
  ) {}

}
