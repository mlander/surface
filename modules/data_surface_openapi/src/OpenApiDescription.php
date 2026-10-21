<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The OpenAPI document, and what it was read from.
 *
 * The document is a payload, an array ready for json_encode(); its
 * cacheability travels beside it, so a response can say it in HTTP terms.
 *
 * @see \Drupal\data_surface_openapi\OpenApiDocument
 */
final class OpenApiDescription implements CacheableDependencyInterface {

  /**
   * Constructs an OpenApiDescription.
   *
   * @param array $document
   *   The OpenAPI 3.1 document.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What it was read from: discovery, every build and option list, the
   *   site's name and the roles' grants; never an account.
   * @param array<string, array{resource: string, operation: string, schema: string, request: string|null}> $operations
   *   Each operation described, by `<resource>:<operation>`: its
   *   resource and operation ids, the component its full schema became,
   *   and the component its request bodies take (the patch, for one that
   *   changes what exists), NULL for a read.
   */
  public function __construct(
    public readonly array $document,
    public readonly CacheableMetadata $cacheability,
    public readonly array $operations,
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
