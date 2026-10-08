<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
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
   * @var array<string, string>
   */
  protected array $slots = [];

  /**
   * {@inheritdoc}
   */
  public function attachBy(string $key, string $by): MapDataDefinition {
    $shell = $this->reserveSubsurface('attachBy', $key);
    $this->slots[$key] = $by;
    return $shell;
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's shape has no cacheability verb; a surface whose shape reads site state needs one, so the owner's shape takes a dependency (not an alter's, which reaches no further than the owner's keys) and the sealed surface carries it.
    $this->builder->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * Gets the slots declared through this shape.
   *
   * @return array<string, string>
   *   The deciding key of each slot, keyed by key, in declaration order.
   *   Discovery's #[SurfaceVariant] classes fill them.
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
