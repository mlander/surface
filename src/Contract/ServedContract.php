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
 * its outputs are Portable Schema documents: the emitter builds them as
 * the dialect's own values, which schema() and outputs() give, and the
 * document holds each in the array form (Schemas::arrayed()), which
 * encodes to the same JSON.
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document,
 * docs/decisions.md#the-emitter-builds-through-the-builder.
 *
 * @see \Drupal\surface\Contract\ContractEmitter
 */
final class ServedContract implements CacheableDependencyInterface {

  /**
   * Constructs a ServedContract.
   *
   * @param array $document
   *   The contract: `resource`, `operation`, `label`, `schema`, `values`,
   *   `stale`, and `outputs` when the data surface declares any.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the refined data surface and the option lists it offers
   *   depend on.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema the document's `schema` was written from.
   * @param \PortableSchema\Builder\Schema|null $outputs
   *   The schema the document's `outputs` was written from, or NULL.
   */
  public function __construct(
    public readonly array $document,
    public readonly CacheableMetadata $cacheability,
    private readonly Schema $schema,
    private readonly ?Schema $outputs = NULL,
  ) {}

  /**
   * Gets the schema of what the operation takes, as a Portable Schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema the emitter built, which the document's `schema` is.
   */
  public function schema(): Schema {
    return $this->schema;
  }

  /**
   * Gets the schema of what the operation answers, as a Portable Schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema the emitter built, which the document's `outputs` is;
   *   NULL where the data surface declares none.
   */
  public function outputs(): ?Schema {
    return $this->outputs;
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
