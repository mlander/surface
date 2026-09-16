<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * A resolved list of allowed values with their meaning.
 *
 * What a constraint means once it has been turned into a list: the
 * allowed values as keys, what each one is called, optional per-option
 * help, and how long the answer may be reused. Form options, and later
 * schema emission, are both projections of this one object, so they
 * cannot describe different value spaces.
 */
final class OptionSet implements CacheableDependencyInterface {

  /**
   * Constructs an OptionSet.
   *
   * @param array $options
   *   The allowed values as keys, mapped to their labels.
   * @param array $descriptions
   *   Per-option help text keyed by the same values; may be partial.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   How long, and on what, the list may be cached. A list read from
   *   the constraint alone is permanent; one read from site state
   *   carries that state's tags.
   */
  public function __construct(
    public readonly array $options,
    public readonly array $descriptions = [],
    public readonly CacheableMetadata $cacheability = new CacheableMetadata(),
  ) {
  }

  /**
   * Returns whether a value is one this set offers.
   *
   * The membership question asked in exactly the way a select answers
   * it: the options are keyed by the value, so the value is looked up as
   * an array key, and PHP's own key coercion makes `'2'` and `2` the
   * same option the way a submitted select does. Anything that cannot be
   * an array key — a boolean, a float, an array, NULL — is not in any
   * option list, so the answer is no rather than a notice.
   *
   * One place answers it because two things ask: the options widget,
   * deciding whether a stored value can still be rendered as a chosen
   * option, and the pipeline, deciding whether a refused value is stale
   * rather than wrong. If those two disagreed, a form would stash a
   * value the pipeline then refused, or warn about one it accepted.
   *
   * @param mixed $value
   *   The value to look for.
   *
   * @return bool
   *   TRUE when the set offers the value.
   */
  public function allows(mixed $value): bool {
    return (is_int($value) || is_string($value)) && array_key_exists($value, $this->options);
  }

  /**
   * Keeps only the values both sets allow.
   *
   * Every constraint on a definition has to hold at once, so a
   * definition carrying more than one list of allowed values allows
   * their intersection. Labels and help text come from this set first,
   * and the cacheability of both is merged, since the answer is only
   * reusable for as long as its shorter-lived half.
   *
   * @param \Drupal\data_surface\Options\OptionSet $other
   *   The set to intersect with.
   *
   * @return \Drupal\data_surface\Options\OptionSet
   *   The intersected set.
   */
  public function intersect(OptionSet $other): OptionSet {
    $options = array_intersect_key($this->options, $other->options);
    $descriptions = array_intersect_key($this->descriptions + $other->descriptions, $options);
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($this->cacheability)
      ->addCacheableDependency($other->cacheability);
    return new self($options, $descriptions, $cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability->getCacheMaxAge();
  }

}
