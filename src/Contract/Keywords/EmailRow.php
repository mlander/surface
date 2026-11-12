<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * The row for Email: `format: email`, on a string, and the check beside it.
 *
 * The format is not the whole constraint: Drupal's validator refuses
 * what `email` admits (`a@b`, `x@localhost`, a quoted local part, an
 * address literal), so the constraint is also named under
 * `portable-check` (`drupal:Email`), with no message of its own
 * (ConstraintKeywords::FORMAT_TWINS). Read back, the format is a leaf
 * type, `email` (LeafTypes::read()),
 * whose data type carries core's Email constraint: the reader answers
 * with the type, and this row reads nothing. So a string key with an
 * Email constraint reads back as an email key; the document stays the
 * same. The dialect's `email` is RFC 5321, ASCII only, while Drupal's
 * validator admits a non-ASCII local part: the format refuses it before
 * the check is asked.
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document.
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
    return new Written(['format' => 'email'], whole: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    // LeafTypes reads the format as the email type.
    return NULL;
  }

}
