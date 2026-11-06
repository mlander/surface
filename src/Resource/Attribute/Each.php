<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On an array property: one value per thing the site has.
 *
 * The spelling of addEach(): a map at the key with one key per entry the
 * provider lists.
 *
 * @code
 * #[Each('boolean', from: TicketTypes::class, label: 'On sale')]
 * public array $on_sale;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::addEach()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Each {

  /**
   * Constructs an Each attribute.
   *
   * @param string|null $type
   *   Each entry's type, of those add() takes or a shape's class; NULL
   *   for a reference per entry, with `to:`.
   * @param string $from
   *   The provider, a SiteListInterface service class.
   * @param string|\Stringable $label
   *   The map's label, written as #[Input]'s is: the values have no
   *   structure to borrow one from.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param mixed $default
   *   What each entry starts from, unless the provider says; NULL for
   *   nothing.
   * @param string|null $to
   *   For a reference per entry, the entity type it refers to.
   * @param string[] $bundles
   *   For a reference per entry, the bundles it may be of; empty for any.
   */
  public function __construct(
    public readonly ?string $type,
    public readonly string $from,
    public readonly string|\Stringable $label,
    public readonly string|\Stringable|null $description = NULL,
    public readonly mixed $default = NULL,
    public readonly ?string $to = NULL,
    public readonly array $bundles = [],
  ) {}

}
