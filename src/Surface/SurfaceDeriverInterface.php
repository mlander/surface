<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * Makes surfaces from facts a class cannot know: one per bundle, say.
 *
 * The plugin system's derivative, for surfaces. A class in a module's
 * src/Surface carrying #[DerivesSurfaces] and implementing this is
 * registered as an autowired service, so it may hold services; the
 * registry asks it once, at discovery, and caches what it answers with
 * the rest of discovery, until one of the answer's cache tags is
 * invalidated.
 *
 * A derivative is a whole surface: the registry ids it
 * `<base>:<derivative>`, and the build step, the alters (which may name
 * the base to alter every derivative), the routes, the catalogue and the
 * derived tools treat it as they treat a class. What it does not have is
 * a class of its own; its shape and situations are methods it names.
 *
 * @see \Drupal\data_surface\Surface\Attribute\DerivesSurfaces
 * @see \Drupal\data_surface\Surface\DerivedSurface
 */
interface SurfaceDeriverInterface {

  /**
   * Derives the surfaces.
   *
   * @param array<class-string, string> $overrides
   *   The classes in an enabled module's src/Surface carrying the
   *   attribute #[DerivesSurfaces(overrides:)] names, valued with their
   *   module: what replaces the derivation where one was written by
   *   hand. Empty when it names none.
   *
   * @return array<string, \Drupal\data_surface\Surface\DerivedSurface>
   *   The surfaces, keyed by derivative id, which holds no colon.
   */
  public function derivatives(array $overrides): array;

}
