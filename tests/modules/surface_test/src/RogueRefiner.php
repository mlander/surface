<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use PortableSchema\Builder\Schema;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * A refiner that breaks the narrowing contract by changing the type.
 */
final class RogueRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values): array {
    return [Schema::integer()];
  }

}
