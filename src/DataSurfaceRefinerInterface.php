<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Narrows data definitions against known sibling values.
 *
 * The narrowing contract: a refiner may tighten constraints, supply a
 * concrete option list, or sharpen a description — but the refined
 * definition must accept only values the one it was handed already
 * accepted. Changing the data type is refused (except from 'any', the
 * declared escape hatch for definitions whose type itself depends on
 * sibling values), as is turning required off, removing a constraint, or
 * replacing one whose options cannot be compared. The contract is
 * enforced by DataSurface::refine() on every link, not trusted.
 *
 * A refiner narrows one contribution. The surface's owner gets the
 * values it declared itself; a module that contributed values with
 * extendChoices() gets those values and no others, so narrowing "its"
 * option away can never take a sibling module's option with it. What
 * each refiner is handed is its own slice of the advertised list, and
 * the refined surface is the union of the slices.
 *
 * A refiner whose answer depends on site state — a list read from
 * entity types, a permission, the current language — says so by also
 * implementing core's CacheableDependencyInterface. Its metadata is
 * merged into the refined surface whenever it runs, which is how a form
 * built from that surface learns it may not be cached forever. There is
 * no second interface for this: cacheability is core's question, asked
 * in core's words.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::addRefiner()
 * @see \Drupal\data_surface\DataSurfaceFilterInterface
 * @see docs/refinement.md
 */
interface DataSurfaceRefinerInterface {

  /**
   * Refines one definition against dependency values.
   *
   * @param string $name
   *   The surface key being refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to refine, holding this contribution's own slice of
   *   the allowed values. Always a deep clone — implementations may
   *   mutate and return it, or return a replacement.
   * @param array $values
   *   The dependency values, keyed by surface key. Every declared
   *   dependency is present and configured.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The refined definition.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface;

}
