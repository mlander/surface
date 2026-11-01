<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * One operation of a derived resource, declared by its deriver.
 *
 * What #[Operation] says on a resource class or its static method, said
 * as a value, because a derived resource has no class to put the
 * attribute on: an id, a label, a description, the static part of
 * access, whether it creates, how a call behaves, and the parameters a
 * route or a tool supplies. A class-based operation reads its parameters
 * off its signature; a derived one names them, since one method serves
 * every derivative: each the name of a key its resource declares, `id`,
 * whose definition the parameter takes. The operation receives the value,
 * and the storage loads what it names.
 *
 * The context is built by a ResourceCallable called with the resolved
 * arguments, keyed by parameter name, before its bound arguments:
 * `edit(array $arguments, string $entity_type, string $bundle)`. Every
 * parameter is required.
 *
 * @see \Drupal\surface\Resource\Attribute\Operation
 */
final class DerivedOperation {

  /**
   * Constructs a DerivedOperation.
   *
   * @param string $id
   *   Machine name, unique within the resource; the context's id.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param \Drupal\surface\Resource\ResourceCallable $context
   *   What builds the OperationContext, handed the arguments by name.
   * @param list<string> $parameters
   *   The parameters: each the name of a key the resource declares, an
   *   identity key or one the operation needs to name its subject, which
   *   a route or a tool supplies as that key's value.
   * @param string|null $permission
   *   The static part of access, with `%key` placeholders, as on
   *   #[Operation].
   * @param bool $destructive
   *   Whether a call may destroy what is there, as on #[Operation].
   * @param bool $readOnly
   *   Whether a call changes nothing, as on #[Operation].
   * @param bool|null $idempotent
   *   Whether the same call twice leaves what the first left, or NULL to
   *   have it derived, as on #[Operation].
   * @param bool $creates
   *   Whether submitting makes a new thing, as on #[Operation]: stamped
   *   on the context, which must agree.
   * @param string|\Stringable|null $description
   *   What a call does to what, as on #[Operation]; NULL for none.
   * @param bool $deletes
   *   Whether a call removes the thing it names, as on #[Operation].
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly ResourceCallable $context,
    public readonly array $parameters = [],
    public readonly ?string $permission = NULL,
    public readonly bool $destructive = FALSE,
    public readonly bool $readOnly = FALSE,
    public readonly ?bool $idempotent = NULL,
    public readonly bool $creates = FALSE,
    public readonly string|\Stringable|null $description = NULL,
    public readonly bool $deletes = FALSE,
  ) {}

}
