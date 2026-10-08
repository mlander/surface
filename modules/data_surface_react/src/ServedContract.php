<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The contract one surface serves for one set of values.
 *
 * The document is a payload, an array ready for json_encode(); what it
 * was read from carries cacheability, which travels beside it rather
 * than inside it, so a response can say it in HTTP terms.
 *
 * @see \Drupal\data_surface_react\ContractEmitter
 */
final class ServedContract implements CacheableDependencyInterface {

  /**
   * Constructs a ServedContract.
   *
   * @param array $document
   *   The contract: `surface`, `situation`, `label`, `schema`, `values`,
   *   `stale`, and `outputs` when the surface declares any.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the refined surface and the option lists it offers depend on.
   */
  public function __construct(
    public readonly array $document,
    public readonly CacheableMetadata $cacheability,
  ) {}

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
