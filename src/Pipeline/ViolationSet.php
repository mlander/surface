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
 * Stale references are held to one side. A set built from a list
 * containing them files them under stale() and nowhere else: they are
 * not iterated, not counted, not named by keys(), and do not make
 * isEmpty() false. That is the whole of "stale never blocks" — every
 * existing reader asks isEmpty() or iterates, so none of them has to
 * learn anything new to keep saving a value that went stale, and a
 * reader that wants to say so out loud asks stale() on purpose.
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
   * The stale references, in the order they were found.
   *
   * A flat list rather than a grouping: nothing renders stale entries
   * per key the way form errors are rendered per element, and every
   * reader so far wants all of them — a messenger warning, an agent's
   * "re-choose these" list.
   *
   * @var \Drupal\data_surface\Pipeline\SurfaceViolation[]
   */
  private readonly array $staleEntries;

  /**
   * Constructs a ViolationSet.
   *
   * @param \Drupal\data_surface\Pipeline\SurfaceViolation[] $violations
   *   The violations, in the order they were found. Entries flagged
   *   stale are separated out here and answered by stale() alone.
   */
  public function __construct(array $violations = []) {
    $grouped = [];
    $stale = [];
    foreach ($violations as $violation) {
      if ($violation->stale) {
        $stale[] = $violation;
        continue;
      }
      $grouped[$violation->key][] = $violation;
    }
    $this->grouped = $grouped;
    $this->staleEntries = $stale;
  }

  /**
   * Gets the stale references, in the order they were found.
   *
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation[]
   *   The stale entries; empty when nothing went stale.
   */
  public function stale(): array {
    return $this->staleEntries;
  }

  /**
   * Returns whether anything in this run referred to a value gone stale.
   *
   * @return bool
   *   TRUE when the set holds at least one stale reference.
   */
  public function hasStale(): bool {
    return $this->staleEntries !== [];
  }

  /**
   * Returns whether nothing was refused.
   *
   * Stale references do not count: they are not refusals, and a run that
   * found only stale entries is a run that may proceed.
   *
   * @return bool
   *   TRUE when the set holds no blocking violations.
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
