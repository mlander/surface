<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A refiner whose answer is only good for as long as site state is.
 *
 * The shape a refiner declares cacheability in: core's own
 * CacheableDependencyInterface, no second interface of this module's.
 * What is declared here is merged into the refined surface whenever this
 * refiner runs, so a form built from that surface knows it holds a list
 * read from a configurable site, for one language, for a minute.
 */
final class CacheableCasingRefiner implements DataSurfaceRefinerInterface, CacheableDependencyInterface {

  /**
   * How long an answer from this refiner may be reused, in seconds.
   */
  public const MAX_AGE = 60;

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $definition->addConstraint('Length', ['max' => 5]);
    return $definition;
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
    return ['data_surface_test:casing'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return self::MAX_AGE;
  }

}
