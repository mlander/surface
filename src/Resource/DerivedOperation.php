<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * One operation of a derived resource, declared by its deriver.
 *
 * What #[Operation] says on a static method of a resource class, said as
 * a value, because a derived resource has no class to put the attribute
 * on: an id, a label, the static part of access, whether it is the
 * default a parent invokes, and the parameters a route or a tool
 * supplies. A class-based operation reads its parameters off its
 * signature; a derived one names them, since one method serves every
 * derivative and a parameter may be named after the derivative (a node
 * is `id`, typed `entity:node`).
 *
 * The context is built by a ResourceCallable called with the resolved
 * arguments, keyed by parameter name, before its bound arguments:
 * `edit(array $arguments, string $entity_type, string $bundle)`. Every
 * parameter is required.
 *
 * @see \Drupal\data_surface\Resource\Attribute\Operation
 */
final class DerivedOperation {

  /**
   * Constructs a DerivedOperation.
   *
   * @param string $id
   *   Machine name, unique within the resource; the context's id.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param \Drupal\data_surface\Resource\ResourceCallable $context
   *   What builds the OperationContext, handed the arguments by name.
   * @param array<string, string> $parameters
   *   The parameters, by name, each typed with a builtin ('string',
   *   'int', 'float', 'bool') or `entity:<entity type id>`, which takes
   *   the entity, or its id, loaded.
   * @param string|null $permission
   *   The static part of access, with `%key` placeholders, as on
   *   #[Operation].
   * @param bool $default
   *   Whether this is the operation it is invoked through as a child
   *   when nothing else picks one.
   * @param bool $destructive
   *   Whether a call may destroy what is there, as on #[Operation].
   * @param bool $readOnly
   *   Whether a call changes nothing, as on #[Operation].
   * @param bool|null $idempotent
   *   Whether the same call twice leaves what the first left, or NULL to
   *   have it derived, as on #[Operation].
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly ResourceCallable $context,
    public readonly array $parameters = [],
    public readonly ?string $permission = NULL,
    public readonly bool $default = FALSE,
    public readonly bool $destructive = FALSE,
    public readonly bool $readOnly = FALSE,
    public readonly ?bool $idempotent = NULL,
  ) {}

}
