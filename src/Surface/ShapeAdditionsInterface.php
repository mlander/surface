<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * What anyone may do to a shape: add to it.
 *
 * Handed to alterInputs() and alterOutputs(). The owner gets
 * ShapeInterface, which adds attachBy().
 *
 * Neither the owner nor an alter may add a key twice: adding is never
 * replacing, so nothing an owner declared can be changed by adding. The
 * one change to an existing key anyone may make is describe(): its label
 * and description, which change nothing about what is accepted.
 */
interface ShapeAdditionsInterface {

  /**
   * Adds a key: name, type, label.
   *
   * Returns the core definition, so the rest (required, constraints,
   * description) is plain core API.
   *
   * @param string $key
   *   The key.
   * @param string $type
   *   The typed data type, as core's typed data manager names it.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   The default value. NULL declares none: a key whose default is NULL
   *   is a key with no default, which is what core definitions already
   *   say by default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, already part of the shape.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition;

  /**
   * Adds a key, the long form.
   *
   * For a definition that is not one simple type: a list, a map.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default value; NULL declares none.
   *
   * @return $this
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

  /**
   * Rewords a key anyone declared.
   *
   * Label and description only: the one change to an existing key an
   * alter may make, because it changes nothing about what is accepted.
   * Type, presence and width stay the owner's; tightening is a
   * #[RefinesInput] method. Not a narrowing, so not checked as one.
   *
   * @param string $key
   *   The key: an owner's key by its name, this shape's own key by its
   *   name, or another alter's key by its mounted path,
   *   `third_party_settings.<module>.<key>` (for an output,
   *   `third_party_outputs.<module>.<key>`).
   * @param string|\Stringable|null $label
   *   The new label, or NULL to leave it.
   * @param string|\Stringable|null $description
   *   The new description, or NULL to leave it.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When no declared key answers to that name.
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL): static;

  /**
   * A subsurface at a key, by class.
   *
   * It keeps its own refinement and its own alters, and sees the context
   * the situation gave it: its own, when the context hands it one with
   * SurfaceContext::withChild(), and otherwise its parent's operation and
   * known identity. Its values are a map at the key, refined, accepted
   * and validated by the child in its own frame; a parent cannot refine
   * into it and it cannot watch a parent key.
   *
   * @param string $key
   *   The key the subsurface sits at.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface> $child
   *   The subsurface.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is already declared, or (for an alter, or for
   *   outputs) not yet built: see the SKETCH GAP notes in the adapters.
   */
  public function attach(string $key, string $child): static;

}
