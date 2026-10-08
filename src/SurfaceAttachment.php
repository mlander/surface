<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\MapDataDefinition;

/**
 * A sealed child surface at one key of its parent: a subsurface.
 *
 * What attach() leaves on the entry it declares, and what each variant of
 * a slot is. The child is a whole surface, not a copy of its
 * definitions: its own refiners, locked keys, secrets and defaults travel
 * with it, so the pipeline and the form hand the value at the key to the
 * child and let it answer for itself, and its refiners run in the
 * child's own frame, under the names the child gave its keys, never under
 * a parent path. That is the wall the pattern draws between a parent and
 * its child, enforced by construction: nothing in the parent can name a
 * key inside the child, and nothing in the child can see the parent.
 *
 * The source is the surface class the child was built from, when it was
 * built from one, so whatever reads the parent can say which surface sits
 * at the key and route its values to that surface's own target.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::attach()
 * @see \Drupal\data_surface\SurfaceSlot
 */
final class SurfaceAttachment {

  /**
   * Constructs a SurfaceAttachment.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The sealed child surface, as it was advertised.
   * @param class-string|null $source
   *   The #[Surface] class the child was built from, or NULL for a child
   *   handed over already sealed.
   */
  public function __construct(
    public readonly DataSurfaceInterface $child,
    public readonly ?string $source = NULL,
  ) {
  }

  /**
   * Builds the map an attached child is advertised as.
   *
   * The map's property definitions are the child's definitions, in the
   * child's order: what the parent says about the key is what the child
   * says about itself, under the label the parent gave the key.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map describing the key itself: its label, description and
   *   required flag. Not changed; a clone is filled.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child, advertised or refined.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  public static function mapOf(MapDataDefinition $shell, DataSurfaceInterface $child): MapDataDefinition {
    $map = clone $shell;
    foreach ($child->getDefinitions() as $name => $definition) {
      $map->setPropertyDefinition((string) $name, $definition);
    }
    return $map;
  }

}
