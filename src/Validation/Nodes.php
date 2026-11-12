<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\surface\ResourceBuild\Location;
use PortableSchema\Formats;
use PortableSchema\Json;

/**
 * The nodes of a document that apply at each place in its data.
 *
 * A walk of the document beside the data: a property's node at the
 * property's value, an item's at each item, a local `$ref` read where it
 * stands, each `allOf` member at the same place. Those nodes apply
 * whatever else holds, and are certain. A node under `anyOf`, `oneOf`,
 * `then` or `else` applies only if its branch is the one that holds, and
 * is uncertain. Nothing is evaluated but what refusedByKeyword() and
 * refusedByComposite() ask.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 *
 * @internal
 */
final class Nodes {

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
   * The nodes at each place, by its JSON Pointer.
   *
   * @var array<string, list<array{0: array, 1: bool}>>
   */
  private array $at = [];

  /**
   * Constructs a Nodes.
   *
   * @param array $root
   *   The document, decoded as arrays.
   * @param mixed $data
   *   The data, as the validator is handed it.
   */
  public function __construct(private readonly array $root, mixed $data) {
    $this->visit($root, $data, '', TRUE, 0);
  }

  /**
   * Answers whether a certain node at a place is read only.
   *
   * A locked key: its value is not the request's, so nothing the request
   * sent can be wrong there.
   *
   * @param string $location
   *   The place.
   *
   * @return bool
   *   TRUE when a certain node there writes `readOnly: true`.
   */
  public function readOnly(string $location): bool {
    foreach ($this->at[$location] ?? [] as [$node, $certain]) {
      if ($certain && ($node['readOnly'] ?? FALSE) === TRUE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a certain node at a place refuses a value by a keyword.
   *
   * The keywords BEFORE_CHECKS name, and `format`: `type`, `pattern`,
   * `maxLength`, `minLength`, each as the validator reads it. A keyword
   * this cannot read (a pattern PHP does not compile) refuses nothing
   * here.
   *
   * @param string $location
   *   The place.
   * @param mixed $value
   *   Its value.
   * @param bool $formats
   *   FALSE to leave `format` out.
   *
   * @return bool
   *   TRUE when one refuses it.
   */
  public function refusedByKeyword(string $location, mixed $value, bool $formats = TRUE): bool {
    foreach ($this->at[$location] ?? [] as [$node, $certain]) {
      if ($certain && self::refuses($node, $value, $formats)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether the `allOf` or `anyOf` a composite check wrote refuses.
   *
   * A composite (Sequentially, AtLeastOneOf) is written as its parts
   * under one keyword and named under `portable-check` at the same node:
   * where those parts refuse the value, by the keywords refusedByKeyword()
   * reads, the check is not asked. An `allOf` refuses where one part
   * refuses; an `anyOf` where every part does. A refusal by any other
   * node at the place does not count.
   *
   * @param string $location
   *   The place.
   * @param mixed $value
   *   Its value.
   * @param string $check
   *   The check's name, as the node names it.
   * @param string $keyword
   *   `allOf` or `anyOf`: the keyword the composite is written as.
   *
   * @return bool
   *   TRUE when a certain node naming the check refuses the value by it.
   */
  public function refusedByComposite(string $location, mixed $value, string $check, string $keyword): bool {
    foreach ($this->at[$location] ?? [] as [$node, $certain]) {
      if (!$certain || !is_array($node['portable-check'] ?? NULL) || !array_key_exists($check, $node['portable-check']) || !is_array($node[$keyword] ?? NULL)) {
        continue;
      }
      if (self::refusesBy($keyword, $node[$keyword], $value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether one node refuses a value by the keywords read here.
   *
   * @param mixed $node
   *   The node.
   * @param mixed $value
   *   The value.
   * @param bool $formats
   *   FALSE to leave `format` out.
   *
   * @return bool
   *   TRUE when one refuses it.
   */
  private static function refuses(mixed $node, mixed $value, bool $formats = TRUE): bool {
    if (!is_array($node)) {
      return $node === FALSE;
    }
    if (isset($node['type']) && !self::typed($value, (array) $node['type'])) {
      return TRUE;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    $length = mb_strlen($value);
    if ((is_int($node['maxLength'] ?? NULL) && $length > $node['maxLength']) || (is_int($node['minLength'] ?? NULL) && $length < $node['minLength'])) {
      return TRUE;
    }
    if (is_string($node['pattern'] ?? NULL) && @preg_match('~' . str_replace('~', '\~', $node['pattern']) . '~uD', $value) === 0) {
      return TRUE;
    }
    return $formats && is_string($node['format'] ?? NULL) && in_array($node['format'], Formats::NAMES, TRUE) && !Formats::check($node['format'], $value);
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
   * Walks one node at one place.
   *
   * @param mixed $node
   *   The node.
   * @param mixed $data
   *   The value at the place.
   * @param string $location
   *   The place.
   * @param bool $certain
   *   Whether the node applies whatever else holds.
   * @param int $depth
   *   How deep the walk is.
   */
  private function visit(mixed $node, mixed $data, string $location, bool $certain, int $depth): void {
    if (!is_array($node) || $depth > self::DEPTH) {
      return;
    }
    $this->at[$location][] = [$node, $certain];
    $ref = $node['$ref'] ?? NULL;
    if (is_string($ref) && str_starts_with($ref, '#')) {
      $this->visit($this->resolve(substr($ref, 1)), $data, $location, $certain, $depth + 1);
    }
    foreach (is_array($node['allOf'] ?? NULL) ? $node['allOf'] : [] as $member) {
      $this->visit($member, $data, $location, $certain, $depth + 1);
    }
    foreach (['anyOf', 'oneOf'] as $keyword) {
      foreach (is_array($node[$keyword] ?? NULL) ? $node[$keyword] : [] as $branch) {
        $this->visit($branch, $data, $location, FALSE, $depth + 1);
      }
    }
    foreach (['then', 'else'] as $keyword) {
      $this->visit($node[$keyword] ?? NULL, $data, $location, FALSE, $depth + 1);
    }
    if (is_array($node['properties'] ?? NULL) && ($data instanceof \stdClass || (is_array($data) && !array_is_list($data)))) {
      $held = (array) $data;
      foreach ($node['properties'] as $name => $property) {
        if (array_key_exists((string) $name, $held)) {
          $this->visit($property, $held[(string) $name], Location::append($location, (string) $name), $certain, $depth + 1);
        }
      }
    }
    if (is_array($node['items'] ?? NULL) && is_array($data) && array_is_list($data)) {
      foreach ($data as $delta => $item) {
        $this->visit($node['items'], $item, Location::append($location, $delta), $certain, $depth + 1);
      }
    }
  }

  /**
   * Reads a node of the document at a JSON Pointer.
   *
   * @param string $pointer
   *   The pointer, `/$defs/event.person`.
   *
   * @return mixed
   *   The node, or NULL where there is none.
   */
  private function resolve(string $pointer): mixed {
    $node = $this->root;
    foreach (Location::segments($pointer) as $segment) {
      if (!is_array($node) || !array_key_exists($segment, $node)) {
        return NULL;
      }
      $node = $node[$segment];
    }
    return $node;
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
   *   TRUE when it is; an empty list is a list and a map alike.
   */
  private static function typed(mixed $value, array $types): bool {
    $type = Json::typeOf($value);
    if ($value === []) {
      return in_array('array', $types, TRUE) || in_array('object', $types, TRUE);
    }
    return in_array($type, $types, TRUE) || ($type === 'integer' && in_array('number', $types, TRUE));
  }

}
