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
 * replacing, so nothing an owner declared can be changed by adding.
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
   * A subsurface at a key, by class.
   *
   * It keeps its own refinement and its own alters, and sees the context
   * the situation gave it.
   *
   * Declared now and built in step 2 of the rework: until then it throws.
   *
   * @param string $key
   *   The key the subsurface sits at.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface> $child
   *   The subsurface.
   *
   * @return $this
   *
   * @throws \LogicException
   *   Always, until subsurfaces are built.
   */
  public function attach(string $key, string $child): static;

}
