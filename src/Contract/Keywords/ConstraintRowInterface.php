<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * One row of the table of constraints and their JSON Schema keywords.
 *
 * A row maps the constraints it names to keywords when the emitter writes
 * a definition, and the keywords back to a constraint when the reader
 * reads a schema. Whether it applies to a value is one answer, read in
 * both directions.
 *
 * @see \Drupal\surface\Contract\ConstraintKeywords
 *
 * @internal
 */
interface ConstraintRowInterface {

  /**
   * Names the constraint plugin ids this row maps.
   *
   * @return string[]
   *   The ids.
   */
  public function constraints(): array;

  /**
   * Names the keywords this row reads that check a value.
   *
   * Where the row does not apply to a value's type, the reader keeps these
   * in the JsonSchema constraint. A `format` is an annotation and is not
   * among them.
   *
   * @return string[]
   *   The keywords.
   */
  public function keywords(): array;

  /**
   * Answers whether the row applies to a value of these JSON types.
   *
   * @param string[] $types
   *   The value's JSON types, null left out.
   *
   * @return bool
   *   TRUE when the row's keywords say something of such a value.
   */
  public function appliesTo(array $types): bool;

  /**
   * Writes a constraint's options as keywords.
   *
   * Called only where the row applies.
   *
   * @param string $id
   *   The constraint plugin id, one of constraints().
   * @param array $options
   *   Its options.
   * @param string[] $types
   *   The value's JSON types, null left out.
   *
   * @return \Drupal\surface\Contract\Keywords\Written|null
   *   The keywords, or NULL when the options cannot be stated.
   */
  public function write(string $id, array $options, array $types): ?Written;

  /**
   * Reads the constraint a schema's keywords state.
   *
   * Called only where the row applies.
   *
   * @param array $schema
   *   The value's schema.
   * @param string[] $types
   *   The value's JSON types, null left out.
   *
   * @return \Drupal\surface\Contract\Keywords\ReadBack|null
   *   The constraint and the keywords it took, or NULL when the schema
   *   holds none of the row's keywords.
   */
  public function read(array $schema, array $types): ?ReadBack;

}
