<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What anyone may do to a shape: add to it. Handed to alterInputs() and
 * alterOutputs(). The owner gets ShapeInterface, which adds attachBy().
 */
interface ShapeAdditionsInterface {

  /**
   * Adds a key: name, type, label. Returns the core definition, so the
   * rest (required, constraints, description) is plain core API.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition;

  /**
   * The long form, for a definition that is not one simple type: a list,
   * a map.
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

  /**
   * A subsurface at a key, by class. It keeps its own refinement and its
   * own alters, and sees the context the situation gave it.
   *
   * Returns the map definition at the key, as add() returns its
   * definition, so the owner labels and describes it with the core
   * setters. describe() is for an alter rewording a key it does not own.
   *
   * @param class-string<\Drupal\surface_sketch\Surface\SurfaceInterface> $child
   */
  public function attach(string $key, string $child): MapDataDefinition;

  /**
   * Offers more values on a key whose allowed values are a fixed list.
   *
   * The one way an alter may widen, and only a choice list: the owner
   * declared the key and the alter adds entries beside the owner's. An
   * alter that extends a key usually also refines it with a
   * #[RefinesInput] method, to say what its new values mean.
   */
  public function extendChoices(string $key, array $choices): static;

  /**
   * Rewords a key anyone declared. Label and description only: the one
   * change to an existing key an alter may make, because it changes
   * nothing about what is accepted. Type, presence and width stay the
   * owner's; tightening is a #[RefinesInput] method.
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL): static;

}
