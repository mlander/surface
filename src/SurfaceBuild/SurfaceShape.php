<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\ShapeInterface;

/**
 * The owner's shape: keys land at the top of the surface.
 *
 * An input goes through setDefinition() and its default through
 * setDefault(); an output goes through setOutputDefinition(), and a
 * default given for one is written onto it so that the engine refuses it
 * in its own words rather than this class guessing at them.
 *
 * @internal
 */
final class SurfaceShape extends ShapeAdapterBase implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public function attachBy(string $key, string $by, array $children = []): static {
    throw static::notYet('attachBy', $key);
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    if ($this->outputs) {
      if ($default !== NULL) {
        DefinitionMetadata::setDefaultValue($definition, $default);
      }
      $this->builder->setOutputDefinition($key, $definition);
      return;
    }
    $this->builder->setDefinition($key, $definition);
    if ($default !== NULL) {
      $this->builder->setDefault($key, $default);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    return $this->outputs
      ? $this->builder->getOutputDefinition($key) !== NULL
      : $this->builder->getDefinition($key) !== NULL;
  }

}
