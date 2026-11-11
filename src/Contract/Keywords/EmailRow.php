<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * The row for Email: `format: idn-email`, on a string.
 *
 * Read back, the format is a leaf type, `email` (LeafTypes::read()),
 * whose data type carries core's Email constraint: the reader answers
 * with the type, and this row reads nothing. So a string key with an
 * Email constraint reads back as an email key; the document stays the
 * same. `idn-email` (RFC 6531) admits the non-ASCII local part Drupal's
 * validator admits.
 *
 * @see \Drupal\surface\Contract\LeafTypes
 *
 * @internal
 */
final class EmailRow implements ConstraintRowInterface {

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return ['Email'];
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
  public function write(string $id, array $options, array $types): Written {
    return new Written(['format' => 'idn-email']);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    // LeafTypes reads the format as the email type.
    return NULL;
  }

}
