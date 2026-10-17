<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Makes resources from facts a class cannot know: one per bundle, say.
 *
 * The plugin system's derivative, for resources. A class in a module's
 * src/Resource carrying #[DerivesResources] and implementing this is
 * registered as an autowired service, so it may hold services; the
 * registry asks it once, at discovery, and caches what it answers with
 * the rest of discovery, until one of the answer's cache tags is
 * invalidated.
 *
 * A derivative is a whole resource (or a structure, when it names
 * neither storage nor identity): the registry ids it
 * `<base>:<derivative>`, and the build step, the alters (which may name
 * the base to alter every derivative), the routes, the catalogue and the
 * derived tools treat it as they treat a class. What it does not have is
 * a class of its own; its shape and operations are methods it names.
 *
 * @see \Drupal\data_surface\Resource\Attribute\DerivesResources
 * @see \Drupal\data_surface\Resource\DerivedResource
 */
interface ResourceDeriverInterface {

  /**
   * Derives the resources.
   *
   * @param array<class-string, string> $overrides
   *   The classes in an enabled module's src/Resource or src/Structure
   *   carrying the attribute #[DerivesResources(overrides:)] names,
   *   valued with their module: what replaces the derivation where one
   *   was written by hand. Empty when it names none.
   *
   * @return array<string, \Drupal\data_surface\Resource\DerivedResource>
   *   The resources, keyed by derivative id, which holds no colon.
   */
  public function derivatives(array $overrides): array;

}
