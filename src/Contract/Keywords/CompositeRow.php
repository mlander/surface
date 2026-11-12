<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

use Drupal\surface\Contract\ConstraintKeywords;

/**
 * A row for one of core's composites: Sequentially or AtLeastOneOf.
 *
 * `Sequentially` is an `allOf` of its parts, in order, and `AtLeastOneOf`
 * an `anyOf`, each part written by the table as the constraint alone would
 * be, on the same type. A part the table cannot state whole (one with no
 * row, one whose row does not apply, a Regex no pattern carries) leaves
 * the whole composite unstated: an `anyOf` short of a part would refuse
 * what that part allows, and an `allOf` short of one would state a rule
 * that is not the one checked. A part that is Email or Uuid is stated by
 * its format, which admits what the constraint refuses: the composite is
 * then written and named under `portable-check` besides, so the check
 * the server runs is one the document names
 * (ConstraintKeywords::FORMAT_TWINS). A part's message is the server's to
 * say.
 *
 * Read back, each part must be one constraint of the table whose keywords
 * are all the part says, but for annotations and a `type` that is the
 * value's own; otherwise the composite is kept in the JsonSchema
 * constraint.
 *
 * Decision: see docs/decisions.md#a-composite-is-its-parts.
 *
 * @internal
 */
final class CompositeRow implements ConstraintRowInterface {

  /**
   * What a part may say beside its constraint's keywords.
   */
  protected const ANNOTATIONS = ['title', 'description', '$comment', 'examples'];

  /**
   * Constructs a CompositeRow.
   *
   * @param string $id
   *   The constraint plugin id.
   * @param string $keyword
   *   `allOf` or `anyOf`.
   */
  public function __construct(
    protected readonly string $id,
    protected readonly string $keyword,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return [$this->id];
  }

  /**
   * {@inheritdoc}
   */
  public function keywords(): array {
    return [$this->keyword];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(array $types): bool {
    // Each part's row answers for the type.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $id, array $options, array $types): ?Written {
    $constraints = $options['constraints'] ?? NULL;
    if (!is_array($constraints) || $constraints === []) {
      return NULL;
    }
    $parts = [];
    $whole = TRUE;
    foreach ($constraints as $constraint) {
      if (!is_array($constraint) || count($constraint) !== 1) {
        return NULL;
      }
      $name = (string) array_key_first($constraint);
      $part = ConstraintKeywords::write($name, $constraint[$name], $types);
      if ($part === NULL || $part->keywords === [] || (!$part->whole && !isset(ConstraintKeywords::FORMAT_TWINS[$name]))) {
        return NULL;
      }
      $whole = $whole && $part->whole;
      $parts[] = $part->keywords;
    }
    return new Written([$this->keyword => $parts], whole: $whole);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    if (!array_key_exists($this->keyword, $schema)) {
      return NULL;
    }
    $value = $schema[$this->keyword];
    $parts = is_array($value) && $value !== [] && array_is_list($value) ? [] : NULL;
    foreach ($parts === NULL ? [] : $value as $part) {
      $read = is_array($part) ? $this->part($part, $types) : NULL;
      if ($read === NULL) {
        $parts = NULL;
        break;
      }
      $parts[] = $read;
    }
    return $parts === NULL
      ? new ReadBack(NULL, kept: [$this->keyword => $value])
      : new ReadBack($this->id, ['constraints' => $parts], [$this->keyword]);
  }

  /**
   * Reads one part as one constraint.
   *
   * @param array $part
   *   The part's schema.
   * @param string[] $types
   *   The value's JSON types.
   *
   * @return array|null
   *   The part as `[name => options]`, or NULL when it is not exactly one
   *   constraint of the table.
   */
  protected function part(array $part, array $types): ?array {
    $said = array_diff_key($part, array_flip(self::ANNOTATIONS));
    if (isset($said['type']) && array_values(array_diff((array) $said['type'], ['null'])) === $types) {
      unset($said['type']);
    }
    $read = ConstraintKeywords::read($said, $types);
    if (count($read) !== 1 || $read[0]->constraint === NULL || $read[0]->kept !== [] || array_diff(array_keys($said), $read[0]->consumed) !== []) {
      return NULL;
    }
    return [$read[0]->constraint => $read[0]->options];
  }

}
