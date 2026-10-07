<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;

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
   * @param class-string<\Drupal\surface_sketch\Surface\SurfaceInterface> $child
   */
  public function attach(string $key, string $child): static;

}
