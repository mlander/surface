<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What only the owner may do, on top of adding. Handed to defineInputs()
 * and defineOutputs(). Outputs may not be refined, which the framework
 * refuses when sealing rather than with a separate type.
 */
interface ShapeInterface extends ShapeAdditionsInterface {

  /**
   * A slot: a subsurface whose shape one sibling INPUT key chooses.
   *
   * The parent never names the children. Every surface carrying
   * #[SurfaceVariant] for this key fills the slot, one per value of $by,
   * and $by's allowed values become exactly those. Until $by has a
   * value the slot is an `any` stub; with one, the framework narrows the
   * stub to that child's map, which is an ordinary refinement under the
   * one rule that `any` may become anything narrower. A child that
   * cannot be enumerated statically is the one case for a #[RefinesInput]
   * method on an `any` key returning the narrower definition itself.
   */
  public function attachBy(string $key, string $by): MapDataDefinition;

  /**
   * A collection: a list whose every item is the child surface.
   *
   * DEFERRED. Declared here so the position is recorded; the next
   * concept after subsurfaces, not part of the current rework.
   *
   * The contract is an ordered list, as typed data lists and field items
   * already are: delta is position, reordering is sending the items in
   * another order, and nothing stores a delta. Storage that must address
   * one item (image effects keyed by uuid with a weight) gets that from
   * an identity key the child declares; the target supplies it on load,
   * generates it on commit for a new item, and turns order into weights
   * in prepare(). The caller never sees a weight.
   *
   * A child's refiners run per item in its own frame. Rules across items
   * (no duplicates, at most five) are constraints on the returned list
   * definition: shape, declared by the parent. Add, remove and reorder
   * are value changes, since the whole list is submitted; they are not
   * situations. Changing one item without resending the list is a later
   * question, probably a situation on the child.
   *
   * @param class-string<\Drupal\surface_sketch\Surface\SurfaceInterface> $child
   */
  public function attachList(string $key, string $child): ListDataDefinition;

  /**
   * A collection whose every item chooses its own shape.
   *
   * DEFERRED, with attachList(). Like attachBy(), but the deciding key
   * is INSIDE each item rather than beside the list (visibility
   * conditions, each naming its plugin). Children mark themselves with
   * #[SurfaceVariant] for this key; the parent never names them.
   */
  public function attachListBy(string $key, string $by): ListDataDefinition;

}
