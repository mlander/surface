<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On an array property: one child per thing the site has.
 *
 * The spelling of attachEach(): a map at the key with one structure per
 * entry the provider lists.
 *
 * @code
 * #[AttachEach(TicketLimitStructure::class, from: TicketTypes::class, reads: ['selling' => 'on_sale'])]
 * public array $limits;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::attachEach()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AttachEach {

  /**
   * Constructs an AttachEach attribute.
   *
   * @param string $structure
   *   What every entry is: a structure's class.
   * @param string $from
   *   The provider, a SiteListInterface service class.
   * @param string|\Stringable|null $label
   *   The map's label, written as #[Input]'s is; NULL for none.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param array<string, string> $reads
   *   Each entry's ports, each mapped to a sibling map from the same
   *   provider, whose entry of the same id it reads.
   */
  public function __construct(
    public readonly string $structure,
    public readonly string $from,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $reads = [],
  ) {}

}
