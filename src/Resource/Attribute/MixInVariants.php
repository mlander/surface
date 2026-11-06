<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a class: the variant a key of it chooses, its members the class's own.
 *
 * The spelling of mixInVariants(). Repeatable. The members of every
 * variant land after every property of the class and after each
 * #[MixIn], in the order the attributes are written; a class that mixes
 * variants in before its own keys keeps defineInputs().
 *
 * @code
 * #[MixInVariants(by: 'kind')]
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::mixInVariants()
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class MixInVariants {

  /**
   * Constructs a MixInVariants attribute.
   *
   * @param string $by
   *   The key of this class whose value chooses the variant.
   * @param string|null $from
   *   The provider of variants from the site, by class, or NULL for the
   *   #[Variant] classes alone.
   * @param list<string|int> $arguments
   *   What the provider lists for, or none.
   */
  public function __construct(
    public readonly string $by,
    public readonly ?string $from = NULL,
    public readonly array $arguments = [],
  ) {}

}
