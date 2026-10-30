<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Traits;

use Opis\JsonSchema\CompliantValidator;

/**
 * Validates values against an emitted schema as a standard client does.
 *
 * Opis/json-schema's own Validator runs with its extensions on, and one
 * of them (allowDefaults) fills a missing property from its `default`
 * before `required` is read, which no standard validator does. The
 * compliant validator turns the extensions off; `format`, an annotation
 * in JSON Schema 2020-12 that a client opts into asserting, is not
 * asserted either, since the contract says it is advisory.
 *
 * Values are encoded the way a JSON client sends a document: a map is an
 * object (empty or not), a list an array, and a null stays a null, which
 * an optional key's schema allows (null clears it). The one null a
 * schema refuses on an optional key is a secret's, which the contract's
 * `values` carry masked; a test reads that one as the key left out.
 *
 * @see docs/served-contract.md#never-stricter-than-the-runtime
 */
trait ContractValidationTrait {

  /**
   * Answers whether a standard validator accepts values under a schema.
   *
   * @param array|\stdClass $schema
   *   The schema, as emitted and decoded by a client.
   * @param mixed $values
   *   The values.
   *
   * @return bool
   *   Whether they validate.
   */
  protected function validates(array|\stdClass $schema, mixed $values): bool {
    $validator = new CompliantValidator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    $validator->parser()->setOption('allowFormats', FALSE);
    return $validator->validate($this->document($values), (string) json_encode($schema))->isValid();
  }

  /**
   * Writes values as the JSON document a client sends.
   *
   * @param mixed $values
   *   The values, as PHP holds them.
   *
   * @return mixed
   *   The decoded JSON: maps as objects, lists as arrays.
   */
  protected function document(mixed $values): mixed {
    $encoded = (string) json_encode($this->objects($values, TRUE), JSON_PRESERVE_ZERO_FRACTION);
    return json_decode($encoded);
  }

  /**
   * Marks every map an object, so an empty one encodes as `{}`.
   *
   * @param mixed $value
   *   A value.
   * @param bool $top
   *   Whether it is the document itself, which is always an object.
   *
   * @return mixed
   *   The value with its maps as objects.
   */
  private function objects(mixed $value, bool $top = FALSE): mixed {
    if ($value instanceof \stdClass) {
      $value = (array) $value;
      $top = TRUE;
    }
    if (!is_array($value)) {
      return $value;
    }
    if (!$top && array_is_list($value)) {
      return array_map(fn (mixed $item): mixed => $this->objects($item), $value);
    }
    return (object) array_map(fn (mixed $item): mixed => $this->objects($item), $value);
  }

}
