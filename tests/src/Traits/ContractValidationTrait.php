<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Traits;

use Opis\JsonSchema\CompliantValidator;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Validates values against an emitted schema as a client of the dialect does.
 *
 * A served document is held by the dialect's own validator, as the
 * server holds a request's values to it: every `format` asserted, a
 * `portable-check` reported unchecked and never refused, since a client
 * runs no check. A served document the dialect cannot load fails the
 * test: that is what the never-stricter tests are there to catch. A
 * schema the caller marks as a fragment (a fragment a reader kept, a
 * standard schema outside the profile) is held by Opis's compliant
 * validator, as JsonSchemaConstraintValidator holds it on the server: no
 * extension, and `format` not asserted.
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
   * @param bool $fragment
   *   TRUE for a schema the dialect is not asked to load: a fragment a
   *   reader kept, or a standard schema outside the profile.
   *
   * @return bool
   *   Whether they validate.
   */
  protected function validates(array|\stdClass $schema, mixed $values, bool $fragment = FALSE): bool {
    if (!$fragment) {
      $object = json_decode((string) json_encode(static::asSent($schema), JSON_PRESERVE_ZERO_FRACTION));
      assert($object instanceof \stdClass, 'A served document is an object.');
      $id = is_string($object->{'$id'} ?? NULL) ? $object->{'$id'} : 'urn:surface:test:' . hash('xxh128', (string) json_encode($object));
      return (new Validator(Registry::withTypes()->add($object, $id)))->validate($this->document($values), $id)->valid;
    }
    $validator = new CompliantValidator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    $validator->parser()->setOption('allowFormats', FALSE);
    return $validator->validate($this->document($values), (string) json_encode($schema))->isValid();
  }

  /**
   * Writes a decoded document's empty maps as the objects a client reads.
   *
   * PHP decodes `{}` and `[]` alike; a check's options, and the members
   * of `properties` and `$defs`, are objects in the document as served.
   *
   * @param mixed $schema
   *   The document, or a node of it.
   *
   * @return mixed
   *   The same, each such empty map an object.
   */
  protected static function asSent(mixed $schema): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $keyword => $value) {
      if (in_array($keyword, ['portable-check', 'properties', '$defs', 'patternProperties'], TRUE) && is_array($value)) {
        $schema[$keyword] = $value === [] ? new \stdClass() : array_map(static fn (mixed $one): mixed => $one === [] ? new \stdClass() : static::asSent($one), $value);
      }
      elseif (is_array($value)) {
        $schema[$keyword] = static::asSent($value);
      }
    }
    return $schema;
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
