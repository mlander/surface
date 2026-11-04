<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a class: a group its keys may be drawn in. Repeatable.
 *
 * A fieldset, or with `details: TRUE` a details element; a key names it
 * by its id with #[Describe(group:)].
 *
 * @code
 * #[Group('details', 'Details')]
 * @endcode
 *
 * @see \Drupal\surface\Resource\ArrangementGroup
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Group {

  /**
   * Constructs a Group attribute.
   *
   * @param string $id
   *   The group's id, unique on the class.
   * @param string|\Stringable $label
   *   Its title, written as #[Input]'s label is.
   * @param bool $details
   *   TRUE for a details element, FALSE for a fieldset.
   * @param bool $collapsed
   *   For a details element, whether it is drawn closed.
   * @param int|null $weight
   *   Its weight among its frame's keys; NULL for none.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly bool $details = FALSE,
    public readonly bool $collapsed = FALSE,
    public readonly ?int $weight = NULL,
    public readonly string|\Stringable|null $description = NULL,
  ) {}

}
