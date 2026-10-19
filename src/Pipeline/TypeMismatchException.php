<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Thrown when a value arrives of a type its definition cannot hold.
 *
 * The sibling of UnknownKeysException, and refused for the same reason: a
 * caller that sends a string where the data surface advertised a map, or a
 * map where it advertised a number, has made a mistake that silence would
 * turn into lost data. Casting is for values that mean the same thing in
 * another notation; a type mismatch means nothing in any notation, so
 * accept() stops rather than guessing.
 *
 * The exception names the dotted path of the offending value and a short
 * description of what was expected and what arrived, so a caller reading
 * either the message or a violation can point at the exact place in a
 * nested payload.
 */
final class TypeMismatchException extends \InvalidArgumentException {

  /**
   * Constructs a TypeMismatchException.
   *
   * @param string $path
   *   The dotted path of the offending value, starting at a top level
   *   key.
   * @param string $expected
   *   A short name for the type the definition holds: a data type such
   *   as 'integer', or 'list' or 'map'.
   * @param string $actual
   *   A short name for the type that arrived, as get_debug_type() names
   *   it.
   */
  public function __construct(
    protected readonly string $path,
    protected readonly string $expected,
    protected readonly string $actual,
  ) {
    parent::__construct(sprintf(
      'The value at "%s" must be of type %s, %s given.',
      $path,
      $expected,
      $actual,
    ));
  }

  /**
   * Gets the dotted path of the offending value.
   *
   * @return string
   *   The path, starting at the top level key that carries the value.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the type the definition holds.
   *
   * @return string
   *   The expected type name.
   */
  public function getExpected(): string {
    return $this->expected;
  }

  /**
   * Gets the type that arrived.
   *
   * @return string
   *   The actual type name.
   */
  public function getActual(): string {
    return $this->actual;
  }

}
