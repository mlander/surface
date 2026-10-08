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
   * The slots declared through this shape, keyed by key.
   *
   * @var array<string, array{by: string, children: array<string, class-string>}>
   */
  protected array $slots = [];

  /**
   * {@inheritdoc}
   */
  public function attachBy(string $key, string $by, array $children = []): static {
    $this->reserveSubsurface('attachBy', $key);
    $this->slots[$key] = ['by' => $by, 'children' => $children];
    return $this;
  }

  /**
   * Gets the slots declared through this shape.
   *
   * @return array<string, array{by: string, children: array<string, class-string>}>
   *   The deciding key and the named children of each slot, keyed by
   *   key, in declaration order. Empty children mark an open slot.
   */
  public function slots(): array {
    return $this->slots;
  }

  /**
   * {@inheritdoc}
   */
  public function declared(): array {
    return array_merge(parent::declared(), array_keys($this->slots));
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
