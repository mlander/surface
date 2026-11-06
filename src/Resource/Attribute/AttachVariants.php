<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On an array property: the variants a sibling key chooses between.
 *
 * The spelling of attachVariants(). Nothing here names the variants: each
 * still says it is one with #[Variant], `of:` this class and `at:` this
 * property's name, or a provider named by `from:` lists them.
 *
 * An attribute for the owner's own keys names the kind of member (Input,
 * Output, Map, Each, Computed, Port); an attribute for a composition
 * restates the verb (Attach, AttachList, AttachEach, AttachVariants,
 * MixIn).
 *
 * @code
 * #[AttachVariants(by: 'pricing', label: 'Ticket')]
 * public array $ticket;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::attachVariants()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AttachVariants {

  /**
   * Constructs an AttachVariants attribute.
   *
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param string|\Stringable|null $label
   *   The label of the map at the key, written as #[Input]'s is; NULL for
   *   none.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param string|null $from
   *   The provider that lists variants for the values no #[Variant]
   *   declares, a SiteListInterface service class; NULL for none.
   * @param list<string|int> $arguments
   *   What a BoundSiteListInterface provider is handed.
   */
  public function __construct(
    public readonly string $by,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly ?string $from = NULL,
    public readonly array $arguments = [],
  ) {}

}
