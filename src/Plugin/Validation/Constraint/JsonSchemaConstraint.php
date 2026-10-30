<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Holds a value to a JSON Schema the data definitions have no words for.
 *
 * What a schema says that no constraint of the vocabulary does (`not`,
 * `contains`, `patternProperties`, `dependentSchemas`, a reference to
 * a document outside it, a type that is several types), kept by
 * SchemaReader as the part of the schema it was read from that is left
 * over, and checked here at runtime against that part, so a structure
 * read from a schema allows what the schema allows. The schema is JSON
 * Schema 2020-12, checked by opis/json-schema with its extensions off,
 * `format` not asserted, as a standard validator reads it. A reference to
 * a document outside the schema is never fetched: a value it would decide
 * is refused, naming the reference.
 *
 * Both documents name it under `x-surface.checkedOnServer`, with the
 * keywords it checks and the schema itself, so a reader of a document
 * reads it back as the same constraint.
 *
 * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
 *
 * @see \Drupal\surface\Contract\SchemaReader
 */
#[Constraint(
  id: 'JsonSchema',
  label: new TranslatableMarkup('JSON Schema', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class JsonSchemaConstraint extends SymfonyConstraint {

  /**
   * The code of a value the schema refuses.
   */
  public const NOT_VALID_ERROR = '0b9d2d6e-6f43-4c38-9a1b-6a0d3c2c5e61';

  /**
   * The code of a value the schema cannot decide, for a reference outside it.
   */
  public const UNRESOLVED_ERROR = '6a3cc1a4-2d1e-4b7e-a9a1-4c1d5b2f7e10';

  /**
   * {@inheritdoc}
   */
  protected const ERROR_NAMES = [
    self::NOT_VALID_ERROR => 'NOT_VALID_ERROR',
    self::UNRESOLVED_ERROR => 'UNRESOLVED_ERROR',
  ];

  /**
   * Constructs a JsonSchemaConstraint.
   *
   * @param array $schema
   *   The JSON Schema 2020-12 the value is held to, as decoded with
   *   objects as arrays: what SchemaReader kept.
   * @param string $message
   *   The error message for a value the schema refuses; `@error` is what
   *   the validator said.
   * @param string $unresolvedMessage
   *   The error message for a value only a document outside the schema
   *   could decide; `@reference` names it.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public array $schema = [],
    public string $message = 'This value does not hold to the schema it was read from: @error',
    public string $unresolvedMessage = 'This value cannot be checked here: the schema it was read from refers to @reference, which is outside it.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  /**
   * Lists the keywords the schema checks, as a client is told them.
   *
   * @param array $schema
   *   The schema.
   *
   * @return string[]
   *   Its keywords at the top, `$defs` left out.
   */
  public static function keywords(array $schema): array {
    return array_values(array_filter(array_map('strval', array_keys($schema)), static fn (string $keyword): bool => $keyword !== '$defs' && $keyword !== '$schema'));
  }

}
