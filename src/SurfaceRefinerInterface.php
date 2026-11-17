<?php

declare(strict_types=1);

namespace Drupal\surface;

use PortableSchema\Builder\Schema;

/**
 * Answers what known sibling values add to a key.
 *
 * The engine's link in a refiner chain. Nothing an author writes
 * implements it: the build step binds each class's #[RefinesInput]
 * methods as one RefinesInputRefiner, which is the implementation, and
 * a class's runtime methods as another, wrapped in
 * Refinement\RuntimeRefiner so the engine can tell the kinds apart.
 *
 * A link is handed the key's schema and gives answers: each a `Schema` of
 * what the values add to the key, or DoesNotApply::here(). The engine
 * applies them, in order, with Refinement\Answer::apply(), which is where
 * the narrowing contract holds: of a bound the stricter stands, a list
 * only filters, nothing in an answer removes what the key says, and an
 * answer that would widen the key otherwise is refused. A link writes on
 * no schema of the key's: a `Schema` is a value.
 *
 * A refiner narrows one contribution. The owner gets the
 * values it declared itself; a module that contributed values with
 * extendChoices() gets those values and no others, so narrowing "its"
 * option away can never take a sibling module's option with it. What
 * each refiner is handed is its own slice of the advertised list, and
 * the refined data surface is the union of the slices.
 *
 * A link that also implements core's CacheableDependencyInterface has
 * its metadata merged into the refined data surface whenever it runs.
 * RefinesInputRefiner does not: a static #[RefinesInput] method calls no
 * service and is a pure function of the siblings it watches, so the site
 * state a refined key depends on is said by the blueprint
 * (BlueprintInterface::addCacheableDependency()) and by the options resolver
 * that fetches the list a constraint points at. RuntimeRefiner does, and
 * says max-age 0: a runtime method reads what the data surface does not
 * describe.
 *
 * @see \Drupal\surface\SurfaceBuilder::addRefiner()
 * @see \Drupal\surface\ResourceBuild\RefinesInputRefiner
 * @see docs/refinement.md
 *
 * @internal
 */
interface SurfaceRefinerInterface {

  /**
   * Answers what the dependency values add to one key.
   *
   * @param string $name
   *   The key being refined.
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema as it stands, holding this contribution's own slice
   *   of the allowed values.
   * @param array $values
   *   The dependency values, keyed by key. Every declared dependency is
   *   present and configured.
   *
   * @return array<int, \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply>
   *   The answers, in the order the link's methods or rules gave them;
   *   empty for none.
   */
  public function refine(string $name, Schema $key, array $values): array;

}
