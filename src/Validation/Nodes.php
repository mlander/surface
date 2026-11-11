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
 * is uncertain. Nothing is evaluated but what refusedByKeyword() asks.
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
   * Lists the places where a node writes one of some formats.
   *
   * Certain or not: a format in a branch that does not hold names a place
   * whose host rule may still apply.
   *
   * @param string[] $formats
   *   The formats.
   *
   * @return array<string, string>
   *   The format at each place, by its JSON Pointer.
   */
  public function formats(array $formats): array {
    $found = [];
    foreach ($this->at as $location => $nodes) {
      foreach ($nodes as [$node]) {
        if (is_string($node['format'] ?? NULL) && in_array($node['format'], $formats, TRUE)) {
          $found[(string) $location] = $node['format'];
          break;
        }
      }
    }
    return $found;
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
      if (!$certain) {
        continue;
      }
      if (isset($node['type']) && !self::typed($value, (array) $node['type'])) {
        return TRUE;
      }
      if (!is_string($value)) {
        continue;
      }
      $length = mb_strlen($value);
      if ((is_int($node['maxLength'] ?? NULL) && $length > $node['maxLength']) || (is_int($node['minLength'] ?? NULL) && $length < $node['minLength'])) {
        return TRUE;
      }
      if (is_string($node['pattern'] ?? NULL) && @preg_match('~' . str_replace('~', '\~', $node['pattern']) . '~uD', $value) === 0) {
        return TRUE;
      }
      if ($formats && is_string($node['format'] ?? NULL) && in_array($node['format'], Formats::NAMES, TRUE) && !Formats::check($node['format'], $value)) {
        return TRUE;
      }
    }
    return FALSE;
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
