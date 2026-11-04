<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a property: an input key, one value or a list of them.
 *
 * The spelling of add(), and with `list:` of addList(), on a class that
 * uses DeclaresByProperties. The key is the property's name, its default
 * the property's initializer, and its type the property's type: `string`,
 * `int`, `float`, `bool` or a shape's class. `type:` names any other type
 * add() takes (`email`, `uri`, `datetime_iso8601`, `timestamp`,
 * `machine_name`).
 *
 * Required is `required:` when given, else what the property says: a
 * non-nullable property with no initializer must be given, and a nullable
 * one with `= NULL` has no default and need not be.
 *
 * @code
 * #[Input('Capacity', description: 'How many people the venue holds.')]
 * #[Constraint('Range', ['min' => 1, 'max' => 100000])]
 * public int $capacity;
 *
 * #[Input('Frequency', required: TRUE)]
 * public string $frequency = 'weekly';
 * @endcode
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::add()
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::addList()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Input {

  /**
   * Constructs an Input attribute.
   *
   * @param string|\Stringable $label
   *   The label: a bare string, which the reader makes translatable, or
   *   `new TranslatableMarkup(...)`, which string extraction finds.
   * @param string|\Stringable|null $description
   *   The description, written as the label is; NULL for none.
   * @param string|null $type
   *   The key type, where the property's type does not say it; NULL to
   *   read it from the property.
   * @param bool|null $required
   *   TRUE or FALSE to say it; NULL to read it from the property.
   * @param string|null $list
   *   What every item of a list is: a type add() takes, a shape's class,
   *   or a plain class of #[Input] properties, for a list of maps. NULL
   *   for one value. A #[Constraint] with `item: TRUE` constrains the item.
   */
  public function __construct(
    public readonly string|\Stringable $label,
    public readonly string|\Stringable|null $description = NULL,
    public readonly ?string $type = NULL,
    public readonly ?bool $required = NULL,
    public readonly ?string $list = NULL,
  ) {}

}
