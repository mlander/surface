<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\surface\Contract\PortableKeywords;
use PortableSchema\Builder\Schema;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * A refiner whose answer is only good for as long as site state is.
 *
 * The shape a refiner declares cacheability in: core's own
 * CacheableDependencyInterface, no second interface of this module's.
 * What is declared here is merged into the refined data surface whenever
 * this refiner runs, so a form built from it knows it holds a list read
 * from a configurable site, for one language, for a minute.
 */
final class CacheableCasingRefiner implements SurfaceRefinerInterface, CacheableDependencyInterface {

  /**
   * How long an answer from this refiner may be reused, in seconds.
   */
  public const MAX_AGE = 60;

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values, string $who): array {
    return [Schema::any()->with(PortableKeywords::CHECK, ['drupal:Length' => ['max' => 5]])];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['languages:language_interface'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return ['surface_test:casing'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return self::MAX_AGE;
  }

}
