<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Thrown when input carries keys the surface does not declare.
 *
 * Unknown keys are refused rather than silently dropped: a caller that
 * misspells a key learns about it instead of watching the value vanish.
 * The exception names every offending key at the level it was found and
 * the dotted path of that level, so the caller can point at the exact
 * place in a nested payload.
 *
 * The message names the first few keys and counts the rest; getKeys()
 * still hands back every one of them.
 *
 * @see \Drupal\data_surface\Pipeline\ViolationSummary
 */
final class UnknownKeysException extends \InvalidArgumentException {

  /**
   * Constructs an UnknownKeysException.
   *
   * @param string[] $keys
   *   The undeclared keys found at one level of the input.
   * @param string $path
   *   The dotted path of the level the keys were found in; the empty
   *   string for the surface's own top level.
   */
  public function __construct(
    protected readonly array $keys,
    protected readonly string $path = '',
  ) {
    parent::__construct(sprintf(
      'Unknown key(s) %s%s.',
      ViolationSummary::fromKeys($keys),
      $path === '' ? '' : ' under ' . $path,
    ));
  }

  /**
   * Gets the undeclared keys.
   *
   * @return string[]
   *   The keys, as they appeared in the input.
   */
  public function getKeys(): array {
    return $this->keys;
  }

  /**
   * Gets the dotted path of the level the keys were found in.
   *
   * @return string
   *   The path, or the empty string at the surface's top level.
   */
  public function getPath(): string {
    return $this->path;
  }

}
