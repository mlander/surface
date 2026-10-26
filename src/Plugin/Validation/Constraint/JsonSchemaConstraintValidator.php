<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\data_surface\Contract\ContractEmitter;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\SchemaException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the JsonSchema constraint.
 *
 * No value given is not a failure, as for every constraint here: whether
 * a key must hold one is its definition's to say. Any other value is
 * written as the JSON a client sends (a map an object, a list an array)
 * and held to the schema by a standard validator. No loader is
 * registered, so a reference outside the schema is never fetched; the
 * value is refused, naming it, rather than accepted unchecked.
 */
final class JsonSchemaConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof JsonSchemaConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $verdict = self::verdict(self::document($value), $constraint->schema);
    if ($verdict === NULL) {
      return;
    }
    [$code, $said] = $verdict;
    $unresolved = $code === JsonSchemaConstraint::UNRESOLVED_ERROR;
    $this->context->buildViolation($unresolved ? $constraint->unresolvedMessage : $constraint->message)
      ->setParameter($unresolved ? '@reference' : '@error', $said)
      ->setCode($code)
      ->addViolation();
  }

  /**
   * Holds a JSON document to a schema, as a standard validator reads it.
   *
   * @param mixed $document
   *   The document, decoded with objects as objects (document()).
   * @param array $schema
   *   The JSON Schema 2020-12.
   *
   * @return array{0: string, 1: string}|null
   *   NULL when the document holds to it; otherwise the error code, and
   *   what the validator said, or the reference it could not resolve.
   */
  public static function verdict(mixed $document, array $schema): ?array {
    $validator = new CompliantValidator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    $validator->parser()->setOption('allowFormats', FALSE);
    try {
      $result = $validator->validate($document, (string) json_encode(['$schema' => ContractEmitter::DIALECT] + $schema, JSON_PRESERVE_ZERO_FRACTION));
    }
    catch (SchemaException $e) {
      return [JsonSchemaConstraint::UNRESOLVED_ERROR, self::reference($e)];
    }
    $error = $result->error();
    if ($error === NULL) {
      return NULL;
    }
    $said = (new ErrorFormatter())->formatFlat($error);
    return [JsonSchemaConstraint::NOT_VALID_ERROR, rtrim((string) (reset($said) ?: $error->message()), '.') . '.'];
  }

  /**
   * Writes a value as the JSON document a client sends.
   *
   * @param mixed $value
   *   The value, as PHP holds it.
   *
   * @return mixed
   *   The decoded JSON: maps as objects, lists as arrays.
   */
  public static function document(mixed $value): mixed {
    return json_decode((string) json_encode(self::objects($value), JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Marks every map an object, so an empty one stays a list.
   *
   * @param mixed $value
   *   A value.
   *
   * @return mixed
   *   The value with its maps as objects.
   */
  protected static function objects(mixed $value): mixed {
    if ($value instanceof \stdClass) {
      $value = (array) $value;
      return (object) array_map(self::objects(...), $value);
    }
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      return array_map(self::objects(...), $value);
    }
    return (object) array_map(self::objects(...), $value);
  }

  /**
   * Says which reference the validator could not resolve.
   *
   * @param \Opis\JsonSchema\Exceptions\SchemaException $e
   *   What it threw.
   *
   * @return string
   *   The reference, or what the validator said.
   */
  protected static function reference(SchemaException $e): string {
    return preg_match('/(\S+:\/\/\S*|#\S*)/', $e->getMessage(), $match) ? trim($match[1], '\'".') : $e->getMessage();
  }

}
