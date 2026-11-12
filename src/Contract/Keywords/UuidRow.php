<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * The row for Uuid: `format: uuid`, on a string.
 *
 * Read back, `format: uuid` on a string is the Uuid constraint with no
 * options. A Uuid that is not `strict` accepts spellings `uuid` refuses,
 * and one with a `normalizer` accepts what it changes first, so neither
 * is stated. Every other is stated, and named under `portable-check`
 * besides (`drupal:Uuid`), since the format admits what the constraint
 * refuses (the nil UUID, a version it does not take), with no message of
 * its own (ConstraintKeywords::FORMAT_TWINS).
 *
 * @internal
 */
final class UuidRow implements ConstraintRowInterface {

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return ['Uuid'];
  }

  /**
   * {@inheritdoc}
   */
  public function keywords(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(array $types): bool {
    return in_array('string', $types, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $id, array $options, array $types): ?Written {
    if (($options['strict'] ?? TRUE) === FALSE || isset($options['normalizer'])) {
      return NULL;
    }
    return new Written(['format' => 'uuid'], whole: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    return ($schema['format'] ?? NULL) === 'uuid' ? new ReadBack('Uuid', [], ['format']) : NULL;
  }

}
