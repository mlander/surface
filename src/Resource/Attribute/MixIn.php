<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a class: a structure mixed in, its members the class's own keys.
 *
 * The spelling of mixIn(). Repeatable. Its members land after every
 * property of the class, in the order the attributes are written; a class
 * that mixes in before its own keys keeps defineInputs().
 *
 * @code
 * #[MixIn(ReviewStructure::class)]
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::mixIn()
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class MixIn {

  /**
   * Constructs a MixIn attribute.
   *
   * @param string $structure
   *   The structure's class.
   * @param array<string, string> $reads
   *   Its ports, each mapped to the key of this class it reads.
   */
  public function __construct(
    public readonly string $structure,
    public readonly array $reads = [],
  ) {}

}
