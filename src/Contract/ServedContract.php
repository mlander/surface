<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use PortableSchema\Builder\Schema;

/**
 * The contract one data surface serves for one set of values.
 *
 * The document is a payload, an array ready for json_encode(); what it
 * was read from carries cacheability, which travels beside it rather
 * than inside it, so a response can say it in HTTP terms. Its schema and
 * its outputs are Portable Schema documents, which schema() and outputs()
 * give as the dialect's own value, read once from the arrays.
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document.
 *
 * @see \Drupal\surface\Contract\ContractEmitter
 */
final class ServedContract implements CacheableDependencyInterface {

  /**
   * The schema read as the dialect's value, once asked.
   */
  private ?Schema $schema = NULL;

  /**
   * The outputs read as the dialect's value, once asked; FALSE for none.
   */
  private Schema|false|null $outputs = NULL;

  /**
   * Constructs a ServedContract.
   *
   * @param array $document
   *   The contract: `resource`, `operation`, `label`, `schema`, `values`,
   *   `stale`, and `outputs` when the data surface declares any.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the refined data surface and the option lists it offers
   *   depend on.
   */
  public function __construct(
    public readonly array $document,
    public readonly CacheableMetadata $cacheability,
  ) {}

  /**
   * Gets the schema of what the operation takes, as a Portable Schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The document's `schema`, read with Schema::from(), which writes the
   *   same JSON back.
   */
  public function schema(): Schema {
    return $this->schema ??= Schema::from($this->document['schema']);
  }

  /**
   * Gets the schema of what the operation answers, as a Portable Schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The document's `outputs`, read with Schema::from(); NULL where the
   *   data surface declares none.
   */
  public function outputs(): ?Schema {
    $this->outputs ??= is_array($this->document['outputs'] ?? NULL) ? Schema::from($this->document['outputs']) : FALSE;
    return $this->outputs ?: NULL;
  }

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
