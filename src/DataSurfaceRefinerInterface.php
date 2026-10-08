<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Narrows data definitions against known sibling values.
 *
 * The engine's link in a refiner chain. Nothing an author writes
 * implements it: the build step binds each class's #[RefinesInput]
 * methods as one RefinesInputRefiner, which is the implementation.
 *
 * The narrowing contract: a refiner may tighten constraints, supply a
 * concrete option list, or sharpen a description — but the refined
 * definition must accept only values the one it was handed already
 * accepted. Changing the data type is refused (except from 'any', the
 * declared escape hatch for definitions whose type itself depends on
 * sibling values, and to a derivative of the type), as is turning
 * required off, removing a constraint, or replacing one whose options
 * cannot be compared. The contract is
 * enforced by DataSurface::refine() on every link, not trusted.
 *
 * A refiner narrows one contribution. The surface's owner gets the
 * values it declared itself; a module that contributed values with
 * extendChoices() gets those values and no others, so narrowing "its"
 * option away can never take a sibling module's option with it. What
 * each refiner is handed is its own slice of the advertised list, and
 * the refined surface is the union of the slices.
 *
 * A link that also implements core's CacheableDependencyInterface has
 * its metadata merged into the refined surface whenever it runs. The
 * one implementation, RefinesInputRefiner, does not: a #[RefinesInput]
 * method calls no service and is a pure function of the siblings it
 * watches, so the site state a refined key depends on is said by the
 * shape (ShapeInterface::addCacheableDependency()) and by the options
 * resolver that fetches the list a constraint points at.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::addRefiner()
 * @see \Drupal\data_surface\SurfaceBuild\RefinesInputRefiner
 * @see docs/refinement.md
 *
 * @internal
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
