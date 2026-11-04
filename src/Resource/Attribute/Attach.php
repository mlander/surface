<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a property: a child at this key, by class.
 *
 * The spelling of attach(). The child is the structure the property is
 * typed with, or `structure:`.
 *
 * @code
 * #[Attach(label: 'Press contact', reads: ['listed' => 'public'])]
 * public PersonStructure $contact;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::attach()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Attach {

  /**
   * Constructs an Attach attribute.
   *
   * @param class-string|null $structure
   *   The child's class; NULL for the property's type.
   * @param string|\Stringable|null $label
   *   The label of the map at the key, written as #[Input]'s is; NULL to
   *   leave the child's.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param array<string, string> $reads
   *   The child's ports, each mapped to the key of this class it reads.
   */
  public function __construct(
    public readonly ?string $structure = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $reads = [],
  ) {}

}
