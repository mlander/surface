<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A link in a refiner chain whose answer only the runtime may give.
 *
 * The build step binds a key's runtime refiners, the #[RefinesInput]
 * methods declared `runtime: TRUE`, as a link of their own wrapped in
 * this, so the engine can tell the two kinds apart without knowing what
 * is behind a link. Refined for values, which is what the pipeline, a
 * form and the served contract's schema for those values do, it runs in
 * its chain like any other link, held to the same narrowing check.
 * Asked what the static refiners alone answer (DataSurfaceInterface::
 * refine() with $runtime FALSE), which is all the served contract may
 * state ahead of time, the engine leaves it out.
 *
 * A link that watches nothing has no gate: it runs on every refinement,
 * whatever the key's other refiners are still waiting for, because there
 * is nothing it could wait for and its answer cannot be fixed at build
 * the way a static refiner that watches nothing is.
 *
 * Its answer was read from something the data surface does not describe,
 * so it holds for the moment it was given: a data surface refined through
 * it is not cacheable (max-age 0), and neither is anything drawn from it.
 * The data surface itself, and the full schema, which never run it, keep
 * their own cacheability.
 *
 * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
 *
 * @internal
 */
final class RuntimeRefiner implements DataSurfaceRefinerInterface, CacheableDependencyInterface, ApplicabilityInterface {

  /**
   * Constructs a RuntimeRefiner.
   *
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface $link
   *   The link that refines: the runtime methods of one class.
   * @param bool $gated
   *   FALSE for a link whose methods watch nothing, which runs on every
   *   refinement; TRUE for one that waits for the key's gate, as every
   *   other link does.
   */
  public function __construct(
    public readonly DataSurfaceRefinerInterface $link,
    public readonly bool $gated = TRUE,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return $this->link->refineDataDefinition($name, $definition, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function mayNotApply(string $name): array {
    return $this->link instanceof ApplicabilityInterface ? $this->link->mayNotApply($name) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->link instanceof CacheableDependencyInterface ? $this->link->getCacheContexts() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->link instanceof CacheableDependencyInterface ? $this->link->getCacheTags() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
