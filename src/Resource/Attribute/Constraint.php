<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: one constraint, by its plugin id. Repeatable.
 *
 * The spelling of addConstraint(), in the order the attributes are
 * written. The options are handed to core as they are, so a text inside
 * them (a message, a choice's label) is written
 * `new TranslatableMarkup(...)`: the reader wraps only the texts it names.
 *
 * @code
 * #[Input('Dietary needs', list: 'string')]
 * #[Constraint('LabeledChoice', ['choices' => ['vegan' => new TranslatableMarkup('Vegan')]], item: TRUE)]
 * public array $dietary = [];
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Constraint {

  /**
   * Constructs a Constraint attribute.
   *
   * @param string $name
   *   The constraint plugin's id.
   * @param array $options
   *   Its options.
   * @param bool $item
   *   TRUE to constrain each item of a list rather than the list.
   */
  public function __construct(
    public readonly string $name,
    public readonly array $options = [],
    public readonly bool $item = FALSE,
  ) {}

}
