<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * The row for NotBlank: `minLength: 1`, on a string.
 *
 * Read back, `minLength: 1` is `Length` with `min: 1`, the literal
 * reading of the keyword, never NotBlank: the document is stable (written
 * again, it is the same keyword), the constraint id is not. Deliberate:
 * the keyword says no more than the length, and the Length row reads
 * every `minLength`. A `normalizer` (trimming, say) makes NotBlank refuse
 * more than the keyword does, so it is named under `checkedOnServer`
 * besides. Whether null is allowed is the key's type, not this row's.
 *
 * @internal
 */
final class NotBlankRow implements ConstraintRowInterface {

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return ['NotBlank'];
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
    return new Written(['minLength' => 1], whole: !isset($options['normalizer']));
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    // The Length row reads `minLength`.
    return NULL;
  }

}
