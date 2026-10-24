<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\StaticRefinerContainer;
use Drupal\data_surface\Resource\Derive;

/**
 * What a computed key is computed from, by what, and when.
 *
 * Written on the key's definition by BlueprintInterface::derive() and read
 * back with of(). A computed key is a key like any other (typed, labeled,
 * constrained, stored), whose value no caller types: the pipeline computes
 * it from the accepted values of the keys `from` names, the narrowing from
 * the values in progress, both through value().
 *
 * Decision: see
 * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
 */
final class Computed {

  /**
   * Constructs what a computed key is computed from.
   *
   * @param string[] $from
   *   The sibling keys it is computed from, in the order the deriver is
   *   handed them.
   * @param class-string<\Drupal\data_surface\Resource\DeriverInterface> $deriver
   *   The class that computes it.
   * @param string $when
   *   Derive::ON_CREATE or Derive::ON_CHANGE.
   */
  public function __construct(
    public readonly array $from,
    public readonly string $deriver,
    public readonly string $when = Derive::ON_CREATE,
  ) {
  }

  /**
   * Reads what a key is computed from, off its definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The key's definition.
   *
   * @return static|null
   *   What it is computed from, or NULL for a key a caller types.
   */
  public static function of(?DataDefinitionInterface $definition): ?static {
    return $definition === NULL ? NULL : DefinitionMetadata::computedOf($definition);
  }

  /**
   * Answers whether the key is computed again for these values.
   *
   * Always while storage holds nothing for it, which is how a thing being
   * created starts; once it holds a value, only when `when` is ON_CHANGE
   * and a key it is computed from holds other than what is stored.
   *
   * @param string $key
   *   The computed key.
   * @param array $values
   *   The values of its level, sources included.
   * @param array $stored
   *   What storage holds for its level.
   *
   * @return bool
   *   TRUE when its value is computed; FALSE when what is stored stands.
   */
  public function recomputes(string $key, array $values, array $stored): bool {
    if (!array_key_exists($key, $stored) || !ValueState::isConfigured($stored[$key])) {
      return TRUE;
    }
    if ($this->when !== Derive::ON_CHANGE) {
      return FALSE;
    }
    foreach ($this->from as $source) {
      // Loosely: what storage holds is read back as sent (a stored "5" is
      // the integer 5), and a map's keys may come in any order.
      if (!array_key_exists($source, $stored) || ($values[$source] ?? NULL) != $stored[$source]) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the value the key holds for these values.
   *
   * @param string $key
   *   The computed key.
   * @param array $values
   *   The values of its level, sources included.
   * @param array $stored
   *   What storage holds for its level.
   *
   * @return mixed
   *   The value computed from the sources, or what is stored when it is
   *   not computed again.
   */
  public function value(string $key, array $values, array $stored): mixed {
    return $this->recomputes($key, $values, $stored) ? $this->derive($values) : $stored[$key];
  }

  /**
   * Computes the value from the sources, with the container guarded.
   *
   * With assertions on, the deriver runs with the global container
   * replaced by one that refuses every service, as a static refiner does:
   * a deriver that asks for one throws, naming it.
   *
   * @param array $values
   *   The values of the key's level, sources included.
   *
   * @return mixed
   *   What the deriver answers.
   */
  public function derive(array $values): mixed {
    $from = [];
    foreach ($this->from as $source) {
      $from[$source] = $values[$source] ?? NULL;
    }
    $deriver = $this->deriver;
    $call = static fn (): mixed => $deriver::derive($from);
    $guarded = FALSE;
    assert(StaticRefinerContainer::asserting($guarded));
    return $guarded ? StaticRefinerContainer::guardDeriver($deriver . '::derive()', $call) : $call();
  }

  /**
   * Sets each computed key of a level to the value it holds for its values.
   *
   * What the narrowing and a form's rebuild show: the value a write of
   * these values would store, computed from the sources as they stand.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing the level.
   * @param array $values
   *   The values in progress, over what is stored.
   * @param array $stored
   *   What storage holds.
   *
   * @return array
   *   The values, each computed key holding its value.
   */
  public static function apply(DataSurfaceInterface $surface, array $values, array $stored): array {
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $computed = static::of($entry->definition);
      if ($computed !== NULL && !$entry->locked) {
        $values[$name] = $computed->value((string) $name, $values, $stored);
      }
    }
    return $values;
  }

  /**
   * Gets the marker both documents write under `x-surface.computed`.
   *
   * @return array{from: string[], when: string}
   *   The keys it is computed from and when.
   */
  public function marker(): array {
    return ['from' => $this->from, 'when' => $this->when];
  }

}
