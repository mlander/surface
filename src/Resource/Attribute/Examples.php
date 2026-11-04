<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: example values, both documents' `examples`.
 *
 * The spelling of DefinitionMetadata::setExamples().
 *
 * @code
 * #[Examples('Harbour Kitchen')]
 * @endcode
 *
 * @see \Drupal\surface\DefinitionMetadata::setExamples()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Examples {

  /**
   * The example values, in order.
   *
   * @var list<mixed>
   */
  public readonly array $values;

  /**
   * Constructs an Examples attribute.
   *
   * @param mixed ...$values
   *   The example values, each of the key's type.
   */
  public function __construct(mixed ...$values) {
    $this->values = array_values($values);
  }

}
