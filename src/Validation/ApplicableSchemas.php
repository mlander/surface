<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\surface\ResourceBuild\Location;
use PortableSchema\Formats;
use PortableSchema\Json;

/**
 * The schemas of a document that apply at each place in its data.
 *
 * A walk of the document beside the data: a property's schema at the
 * property's value, an item's at each item, a local `$ref` read where it
 * stands, each `allOf` member at the same place. Those schemas apply
 * whatever else holds, and are certain. A schema under `anyOf`, `oneOf`,
 * `then` or `else` applies only if its branch is the one that holds, and
 * is uncertain. Nothing is evaluated but what refusedByKeyword() and
 * refusedByComposite() ask.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 *
 * @internal
 */
final class ApplicableSchemas {

  /**
   * How deep a walk goes, references followed at one place included.
   */
  private const DEPTH = 64;

  /**
   * The keywords whose refusal leaves a check at the same place unasked.
   *
   * Their codes, as the validator files them; a `format` by `format.`.
   */
  public const BEFORE_CHECKS = ['type', 'pattern', 'maxLength', 'minLength'];

  /**
   * The schemas at each place, by its JSON Pointer.
   *
   * Each with whether it is certain and the branch it stands in: the
   * branches around it from the top, each numbered as visit() reaches
   * it (`/3/9`), the empty string for a certain one.
   *
   * @var array<string, list<array{0: array, 1: bool, 2: string}>>
   */
  private array $at = [];

  /**
   * How many branches visit() has numbered.
   */
  private int $branches = 0;

  /**
   * Constructs a Schemas.
   *
   * @param array $root
   *   The document, decoded as arrays.
   * @param mixed $data
   *   The data, as the validator is handed it.
   */
  public function __construct(private readonly array $root, mixed $data) {
    $this->visit($root, $data, '', '', 0);
  }

  /**
   * Answers whether a certain schema at a place is read only.
   *
   * A locked key: its value is not the request's, so nothing the request
   * sent can be wrong there.
   *
   * @param string $location
   *   The place.
   *
   * @return bool
   *   TRUE when a certain schema there writes `readOnly: true`.
   */
  public function readOnly(string $location): bool {
    foreach ($this->at[$location] ?? [] as [$schema, $certain]) {
      if ($certain && ($schema['readOnly'] ?? FALSE) === TRUE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a schema that holds at a place refuses a value.
   *
   * The keywords BEFORE_CHECKS name, and `format`: `type`, `pattern`,
   * `maxLength`, `minLength`, each as the validator reads it. A keyword
   * this cannot read (a pattern PHP does not compile) refuses nothing
   * here.
   *
   * A schema under `anyOf`, `oneOf`, `then` or `else` counts for a check
   * it names itself: the validator asks that check only where it
   * evaluates that schema, so the schema's own keywords hold the value the
   * check is handed (a block's settings, one branch for each plugin). A
   * request does not say which schema named it, so the check is left
   * unasked only where every schema at the place naming it refuses the
   * value.
   *
   * @param string $location
   *   The place.
   * @param mixed $value
   *   Its value.
   * @param bool $formats
   *   FALSE to leave `format` out.
   * @param string|null $check
   *   The name of the check asked, as a schema names it under
   *   `portable-check`; NULL to read the certain ones alone.
   *
   * @return bool
   *   TRUE when one refuses it.
   */
  public function refusedByKeyword(string $location, mixed $value, bool $formats = TRUE, ?string $check = NULL): bool {
    $naming = [];
    foreach ($this->at[$location] ?? [] as [$schema, $certain, $branch]) {
      if ($certain && self::refuses($schema, $value, $formats)) {
        return TRUE;
      }
      if ($check !== NULL && is_array($schema['portable-check'] ?? NULL) && array_key_exists($check, $schema['portable-check'])) {
        $naming[] = $branch;
      }
    }
    if ($naming === []) {
      return FALSE;
    }
    foreach ($naming as $named) {
      if (!$this->refusedWithin($location, $value, $formats, $named)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Answers whether a schema that holds with a branch refuses a value.
   *
   * A schema holds wherever a branch is evaluated when it stands in that
   * branch or in one the branch is inside of: the branch's own `$ref`,
   * `allOf` members and properties, and the branches around it.
   *
   * @param string $location
   *   The place.
   * @param mixed $value
   *   Its value.
   * @param bool $formats
   *   FALSE to leave `format` out.
   * @param string $branch
   *   The branch, as visit() names it.
   *
   * @return bool
   *   TRUE when one refuses it.
   */
  private function refusedWithin(string $location, mixed $value, bool $formats, string $branch): bool {
    foreach ($this->at[$location] ?? [] as [$schema, , $around]) {
      if (($around === '' || $around === $branch || str_starts_with($branch, $around . '/')) && self::refuses($schema, $value, $formats)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether the `allOf` or `anyOf` a composite check wrote refuses.
   *
   * A composite (Sequentially, AtLeastOneOf) is written as its parts
   * under one keyword and named under `portable-check` at the same schema:
   * where those parts refuse the value, by the keywords refusedByKeyword()
   * reads, the check is not asked. An `allOf` refuses where one part
   * refuses; an `anyOf` where every part does. A refusal by any other
   * schema at the place does not count.
   *
   * @param string $location
   *   The place.
   * @param mixed $value
   *   Its value.
   * @param string $check
   *   The check's name, as the schema names it.
   * @param string $keyword
   *   `allOf` or `anyOf`: the keyword the composite is written as.
   *
   * @return bool
   *   TRUE when a certain schema naming the check refuses the value by it.
   */
  public function refusedByComposite(string $location, mixed $value, string $check, string $keyword): bool {
    foreach ($this->at[$location] ?? [] as [$schema, $certain]) {
      if (!$certain || !is_array($schema['portable-check'] ?? NULL) || !array_key_exists($check, $schema['portable-check']) || !is_array($schema[$keyword] ?? NULL)) {
        continue;
      }
      if (self::refusesBy($keyword, $schema[$keyword], $value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether one schema refuses a value by the keywords read here.
   *
   * @param mixed $schema
   *   The schema.
   * @param mixed $value
   *   The value.
   * @param bool $formats
   *   FALSE to leave `format` out.
   *
   * @return bool
   *   TRUE when one refuses it.
   */
  private static function refuses(mixed $schema, mixed $value, bool $formats = TRUE): bool {
    if (!is_array($schema)) {
      return $schema === FALSE;
    }
    if (isset($schema['type']) && !self::typed($value, (array) $schema['type'])) {
      return TRUE;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    $length = mb_strlen($value);
    if ((is_int($schema['maxLength'] ?? NULL) && $length > $schema['maxLength']) || (is_int($schema['minLength'] ?? NULL) && $length < $schema['minLength'])) {
      return TRUE;
    }
    if (is_string($schema['pattern'] ?? NULL) && @preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~uD', $value) === 0) {
      return TRUE;
    }
    return $formats && is_string($schema['format'] ?? NULL) && in_array($schema['format'], Formats::NAMES, TRUE) && !Formats::check($schema['format'], $value);
  }

  /**
   * Answers whether the parts of an `allOf` or `anyOf` refuse a value.
   *
   * @param string $keyword
   *   `allOf` or `anyOf`.
   * @param array $parts
   *   Its parts.
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   For `allOf`, TRUE when one part refuses; for `anyOf`, when every
   *   part does.
   */
  private static function refusesBy(string $keyword, array $parts, mixed $value): bool {
    if ($parts === []) {
      return FALSE;
    }
    foreach ($parts as $part) {
      $refused = self::refuses($part, $value)
        || (is_array($part) && is_array($part['allOf'] ?? NULL) && self::refusesBy('allOf', $part['allOf'], $value))
        || (is_array($part) && is_array($part['anyOf'] ?? NULL) && self::refusesBy('anyOf', $part['anyOf'], $value));
      if ($keyword === 'allOf' && $refused) {
        return TRUE;
      }
      if ($keyword === 'anyOf' && !$refused) {
        return FALSE;
      }
    }
    return $keyword === 'anyOf';
  }

  /**
   * Walks one schema at one place.
   *
   * @param mixed $schema
   *   The schema.
   * @param mixed $data
   *   The value at the place.
   * @param string $location
   *   The place.
   * @param string $branch
   *   The branch it stands in, the empty string where it applies whatever
   *   else holds.
   * @param int $depth
   *   How deep the walk is.
   */
  private function visit(mixed $schema, mixed $data, string $location, string $branch, int $depth): void {
    if (!is_array($schema) || $depth > self::DEPTH) {
      return;
    }
    $this->at[$location][] = [$schema, $branch === '', $branch];
    $ref = $schema['$ref'] ?? NULL;
    if (is_string($ref) && str_starts_with($ref, '#')) {
      $this->visit($this->resolve(substr($ref, 1)), $data, $location, $branch, $depth + 1);
    }
    foreach (is_array($schema['allOf'] ?? NULL) ? $schema['allOf'] : [] as $member) {
      $this->visit($member, $data, $location, $branch, $depth + 1);
    }
    foreach (['anyOf', 'oneOf'] as $keyword) {
      foreach (is_array($schema[$keyword] ?? NULL) ? $schema[$keyword] : [] as $member) {
        $this->visit($member, $data, $location, $branch . '/' . ++$this->branches, $depth + 1);
      }
    }
    foreach (['then', 'else'] as $keyword) {
      $this->visit($schema[$keyword] ?? NULL, $data, $location, $branch . '/' . ++$this->branches, $depth + 1);
    }
    if (is_array($schema['properties'] ?? NULL) && ($data instanceof \stdClass || (is_array($data) && !array_is_list($data)))) {
      $held = (array) $data;
      foreach ($schema['properties'] as $name => $property) {
        if (array_key_exists((string) $name, $held)) {
          $this->visit($property, $held[(string) $name], Location::append($location, (string) $name), $branch, $depth + 1);
        }
      }
    }
    if (is_array($schema['items'] ?? NULL) && is_array($data) && array_is_list($data)) {
      foreach ($data as $delta => $item) {
        $this->visit($schema['items'], $item, Location::append($location, $delta), $branch, $depth + 1);
      }
    }
  }

  /**
   * Reads a schema of the document at a JSON Pointer.
   *
   * @param string $pointer
   *   The pointer, `/$defs/event.person`.
   *
   * @return mixed
   *   The schema, or NULL where there is none.
   */
  private function resolve(string $pointer): mixed {
    $schema = $this->root;
    foreach (Location::segments($pointer) as $segment) {
      if (!is_array($schema) || !array_key_exists($segment, $schema)) {
        return NULL;
      }
      $schema = $schema[$segment];
    }
    return $schema;
  }

  /**
   * Answers whether a value is of one of some JSON types.
   *
   * @param mixed $value
   *   The value.
   * @param array $types
   *   The types.
   *
   * @return bool
   *   TRUE when it is; an empty list is a list and a map alike, and a
   *   PHP array whose keys are not 0 to n - 1 is a map, as the validator
   *   reads them.
   */
  private static function typed(mixed $value, array $types): bool {
    $type = Json::typeOf($value);
    if ($value === []) {
      return in_array('array', $types, TRUE) || in_array('object', $types, TRUE);
    }
    if (is_array($value) && !array_is_list($value)) {
      $type = 'object';
    }
    return in_array($type, $types, TRUE) || ($type === 'integer' && in_array('number', $types, TRUE));
  }

}
