<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * The marker a producer returns for an output it is not emitting.
 *
 * An output key that is gated, irrelevant, or simply not produced on
 * this run is **absent** from the emitted array. NULL is not that: NULL
 * is a value like any other, it is emitted, and it has to satisfy the
 * output definition the same way 0 or the empty string would.
 *
 * PHP has no way to leave a key out of an array expression, so a
 * producer that assembles its outputs in one literal — which is what a
 * readable formatValue() or doExecute() looks like — cannot say "and
 * this one is not emitted" without breaking the literal apart. This
 * sentinel is how it says it: return Omitted::value() for the key, and
 * conformance strips the key before anything else looks at the array.
 *
 * @code
 * return [
 *   'text' => $text,
 *   // Nothing about this run selects a variant, so no variant is
 *   // emitted — as opposed to a variant that is emitted and empty.
 *   'classes' => $variant === NULL ? Omitted::value() : ['v-' . $variant],
 * ];
 * @endcode
 *
 * The rules, in three lines:
 * - Conformance strips every Omitted key, at every depth, before it
 *   checks anything.
 * - An absent key — never sent, or sent as Omitted — is legal exactly
 *   when its output definition is not required.
 * - NULL is a value and is checked against the definition, so a
 *   required key sent as NULL is a violation and an optional one is
 *   not.
 *
 * A singleton because identity is the whole point: a producer compares
 * nothing, it returns the marker, and is() answers by instance rather
 * than by a magic string a real value could collide with.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::conformOutput()
 * @see docs/outputs.md
 */
final class Omitted {

  /**
   * The one instance.
   *
   * @var \Drupal\data_surface\Pipeline\Omitted|null
   */
  private static ?self $instance = NULL;

  /**
   * Constructs the sentinel.
   *
   * Private: there is one Omitted, and code that holds it holds that
   * one, so an identity comparison is a safe way to recognize it.
   */
  private function __construct() {
  }

  /**
   * Gets the sentinel.
   *
   * @return self
   *   The one instance.
   */
  public static function value(): self {
    return self::$instance ??= new self();
  }

  /**
   * Returns whether a value is the sentinel.
   *
   * @param mixed $value
   *   The value to judge.
   *
   * @return bool
   *   TRUE when the value says "this key is not emitted".
   */
  public static function is(mixed $value): bool {
    return $value instanceof self;
  }

  /**
   * Removes every omitted key from an emitted array, at every depth.
   *
   * Depth matters because a map output's properties are outputs too: a
   * producer that emits a map with one property it has nothing to say
   * about marks that property rather than the whole map.
   *
   * @param array $output
   *   The emitted values.
   *
   * @return array
   *   The same values with the omitted keys gone. Keys that survive keep
   *   their order, and a list closes its gaps rather than keeping the
   *   holes an omitted item left: a list with nothing at delta 1 is not
   *   a shape any consumer of a list expects.
   */
  public static function strip(array $output): array {
    $list = array_is_list($output);
    $stripped = [];
    foreach ($output as $name => $value) {
      if (self::is($value)) {
        continue;
      }
      $stripped[$name] = is_array($value) ? self::strip($value) : $value;
    }
    return $list ? array_values($stripped) : $stripped;
  }

  /**
   * Refuses to be cloned, so the identity comparison stays sound.
   *
   * @throws \LogicException
   *   Always.
   */
  public function __clone() {
    throw new \LogicException('The Omitted sentinel cannot be cloned: it is recognized by identity, and a copy of it would be a value nothing recognizes.');
  }

}
