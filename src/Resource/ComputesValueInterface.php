<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Computes a key's value from the values of other keys.
 *
 * Named by BlueprintInterface::compute(), never instantiated: compute() is
 * static, a pure function of the values it is handed. The pipeline calls
 * it with the accepted values of the keys `from:` names, and the narrowing
 * calls it with the values in progress, so the same values in must give
 * the same value out, whatever is stored, whoever asks and whenever. With
 * assertions on it runs with the global container guarded
 * (Pipeline\PipelineGuard::guardCompute()), which refuses every
 * service, and the module's PHPStan rule flags a call to `\Drupal::` in
 * it. A value that needs a service or the clock (a changed time) is no
 * computed key: a storage's prepare() adds it.
 *
 * @code
 * final class Slugify implements ComputesValueInterface {
 *
 *   public static function compute(array $from): mixed {
 *     $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($from['label'] ?? ''))), '-');
 *     return $slug === '' ? NULL : $slug;
 *   }
 *
 * }
 * @endcode
 *
 * Decision: see
 * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
 */
interface ComputesValueInterface {

  /**
   * Computes the value.
   *
   * @param array<string, mixed> $from
   *   The value of each key `from:` names, by key, in the order it names
   *   them: as accepted, so each in its declared type or NULL.
   *
   * @return mixed
   *   The value, in the key's type, or NULL for none.
   */
  public static function compute(array $from): mixed;

}
