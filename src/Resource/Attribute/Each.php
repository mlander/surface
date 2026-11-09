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
 * An entry that refers to an entity names the id's type, and carries the
 * reference constraint on each entry, `item: TRUE`, since an attribute
 * argument is a constant expression and no definition:
 *
 * @code
 * #[Each('integer', from: Slots::class, label: 'Pages')]
 * #[Constraint('SurfaceValidReference', ['entityTypeId' => 'node', 'bundles' => ['page']], item: TRUE)]
 * public array $pages;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::addEach()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Each {

  /**
   * Constructs an Each attribute.
   *
   * @param string $type
   *   Each entry's type, of those add() takes or a shape's class; for a
   *   reference per entry, the type the entity type keeps its id as.
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
   */
  public function __construct(
    public readonly string $type,
    public readonly string $from,
    public readonly string|\Stringable $label,
    public readonly string|\Stringable|null $description = NULL,
    public readonly mixed $default = NULL,
  ) {}

}
