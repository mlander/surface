<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

use Drupal\surface\Resource\Compute;

/**
 * On a property: a computed key, whose value nobody types.
 *
 * The spelling of compute(). The type is the property's, or `type:`. A
 * computed key is never required by what the property says: whether a
 * value must be sent is the compute's to say, with `when:`.
 *
 * @code
 * #[Computed('Slug', from: ['label'], using: Slugify::class)]
 * public string $slug;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::compute()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Computed {

  /**
   * Constructs a Computed attribute.
   *
   * @param string|\Stringable $label
   *   The label, written as #[Input]'s is.
   * @param string[] $from
   *   The keys it is computed from.
   * @param string $using
   *   The class that computes it, implementing ComputesValueInterface.
   * @param string|null $type
   *   The key type; NULL to read it from the property.
   * @param string $when
   *   When it is computed, one of the Compute constants.
   * @param string|\Stringable|null $description
   *   The description; NULL for none.
   */
  public function __construct(
    public readonly string|\Stringable $label,
    public readonly array $from,
    public readonly string $using,
    public readonly ?string $type = NULL,
    public readonly string $when = Compute::ON_CREATE,
    public readonly string|\Stringable|null $description = NULL,
  ) {}

}
