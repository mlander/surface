<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * The row for UniqueItems: `uniqueItems: true`, on an array.
 *
 * The table writes `uniqueItems` after every other keyword
 * (ConstraintKeywords::LAST), where the reader reads it, so a list reads
 * back to itself whatever order its constraints were added in.
 * `uniqueItems: false` is what an array says with no keyword at all, and
 * reads as nothing.
 *
 * @internal
 */
final class UniqueItemsRow implements ConstraintRowInterface {

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return ['UniqueItems'];
  }

  /**
   * {@inheritdoc}
   */
  public function keywords(): array {
    return ['uniqueItems'];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(array $types): bool {
    return in_array('array', $types, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $id, array $options, array $types): Written {
    return new Written(['uniqueItems' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    if (!array_key_exists('uniqueItems', $schema)) {
      return NULL;
    }
    $value = $schema['uniqueItems'];
    if (!is_bool($value)) {
      return new ReadBack(NULL, kept: ['uniqueItems' => $value], note: 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it.');
    }
    return new ReadBack($value ? 'UniqueItems' : NULL, [], ['uniqueItems']);
  }

}
