<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On an array property: a collection, a list whose every item is a child.
 *
 * The spelling of attachList().
 *
 * @code
 * #[AttachList(VenueRoomStructure::class, max: 20, label: 'Rooms')]
 * public array $rooms;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::attachList()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AttachList {

  /**
   * Constructs an AttachList attribute.
   *
   * @param string $structure
   *   What every item is: its class, or a derived resource's id.
   * @param string|\Stringable|null $label
   *   The list's label, written as #[Input]'s is; NULL for none.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param int|null $min
   *   The fewest items, or NULL for no bound.
   * @param int|null $max
   *   The most items, or NULL for unlimited.
   */
  public function __construct(
    public readonly string $structure,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly ?int $min = NULL,
    public readonly ?int $max = NULL,
  ) {}

}
