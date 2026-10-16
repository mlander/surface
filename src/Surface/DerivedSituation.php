<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * One situation of a derived surface, declared by its deriver.
 *
 * What #[Situation] says on a static method of a surface class, said as
 * a value, because a derived surface has no class to put the attribute
 * on: an id, a label, the static part of access, whether it is the
 * default a parent invokes, and the parameters a route or a tool
 * supplies. A class-based situation reads its parameters off its
 * signature; a derived one names them, since one method serves every
 * derivative and a parameter may be named after the derivative (a node
 * is `id`, typed `entity:node`).
 *
 * The context is built by a SurfaceCallable called with the resolved
 * arguments, keyed by parameter name, before its bound arguments:
 * `edit(array $arguments, string $entity_type, string $bundle)`. Every
 * parameter is required.
 *
 * @see \Drupal\data_surface\Surface\Attribute\Situation
 */
final class DerivedSituation {

  /**
   * Constructs a DerivedSituation.
   *
   * @param string $id
   *   Machine name, unique within the surface; the context's operation.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param \Drupal\data_surface\Surface\SurfaceCallable $context
   *   What builds the SurfaceContext, handed the arguments by name.
   * @param array<string, string> $parameters
   *   The parameters, by name, each typed with a builtin ('string',
   *   'int', 'float', 'bool') or `entity:<entity type id>`, which takes
   *   the entity, or its id, loaded.
   * @param string|null $permission
   *   The static part of access, with `%key` placeholders, as on
   *   #[Situation].
   * @param bool $default
   *   Whether this is the situation the surface is invoked through as a
   *   child when nothing else picks one.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly SurfaceCallable $context,
    public readonly array $parameters = [],
    public readonly ?string $permission = NULL,
    public readonly bool $default = FALSE,
  ) {}

}
