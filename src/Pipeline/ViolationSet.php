<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Everything one run refused, grouped by surface key.
 *
 * Modeled on core's constraint violation list: a countable, iterable
 * collection of violations rather than an array of arrays whose shape
 * every reader had to remember. What used to be
 * `$violations['title'][0]['message']` is a SurfaceViolation with a
 * key, a path and a message, and what used to be `$violations === []`
 * is isEmpty().
 *
 * Constructor only: a set is built once, from the violations one stage
 * found, and never added to afterwards. A stage that finds violations
 * collects them in a plain list and hands that list over, which is both
 * the simplest thing to write and the only shape that cannot be half
 * built. Nothing in the module merges two sets, so nothing here does.
 *
 * Iteration is grouped: every violation on the first key that has one,
 * then every violation on the next, in the order the keys were first
 * refused. That is the order the messages came out in when this was an
 * array of arrays, and summaries and form errors read the same as they
 * always did.
 *
 * @see \Drupal\data_surface\Pipeline\SurfaceViolation
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::validate()
 *
 * @implements \IteratorAggregate<int, \Drupal\data_surface\Pipeline\SurfaceViolation>
 */
final class ViolationSet implements \IteratorAggregate, \Countable {

  /**
   * The violations, keyed by surface key, in first-refused order.
   *
   * @var array<string, \Drupal\data_surface\Pipeline\SurfaceViolation[]>
   */
  private readonly array $grouped;

  /**
   * Constructs a ViolationSet.
   *
   * @param \Drupal\data_surface\Pipeline\SurfaceViolation[] $violations
   *   The violations, in the order they were found.
   */
  public function __construct(array $violations = []) {
    $grouped = [];
    foreach ($violations as $violation) {
      $grouped[$violation->key][] = $violation;
    }
    $this->grouped = $grouped;
  }

  /**
   * Returns whether nothing was refused.
   *
   * @return bool
   *   TRUE when the set holds no violations.
   */
  public function isEmpty(): bool {
    return $this->grouped === [];
  }

  /**
   * Returns whether a surface key was refused.
   *
   * @param string $key
   *   The surface key.
   *
   * @return bool
   *   TRUE when the key carries at least one violation.
   */
  public function has(string $key): bool {
    return isset($this->grouped[$key]);
  }

  /**
   * Gets the violations filed under one surface key.
   *
   * @param string $key
   *   The surface key.
   *
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation[]
   *   The violations, in the order they were found; empty when the key
   *   was not refused.
   */
  public function byKey(string $key): array {
    return $this->grouped[$key] ?? [];
  }

  /**
   * Gets the refused surface keys, in the order they were first refused.
   *
   * @return string[]
   *   The keys.
   */
  public function keys(): array {
    return array_keys($this->grouped);
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Traversable {
    foreach ($this->grouped as $violations) {
      foreach ($violations as $violation) {
        yield $violation;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return array_sum(array_map('count', $this->grouped));
  }

}
