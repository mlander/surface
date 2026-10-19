<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Names one operation: one way a resource or structure is asked for.
 *
 * Goes on a static method that returns an OperationContext. What the
 * operation needs is the method's own signature, read by reflection, so
 * it is never written twice.
 *
 * Found by attribute, like a hook method, in two places:
 *   - on the resource's or structure's own class, with no `of`;
 *   - on any class in src/Alter, with `of` naming that class.
 * So another module can add a way to ask for a resource it does not
 * own, usually by building on one of the owner's operations.
 *
 * With this, an operation can be listed, addressed and generated from:
 *   - a discovery document lists every resource with its operations;
 *   - a tool exists per operation, `field.instance:edit`, its inputs
 *     being the method's parameters plus the resource's open keys;
 *   - a route maps its parameters onto one operation;
 *   - the context's id is the operation id, nothing else.
 *
 * It also states how a call behaves, for a client deciding whether to
 * ask before calling and whether a retry is safe: whether it changes
 * nothing, whether it may destroy what is there, and whether the same
 * call twice leaves what the first left. A tool carries them to the Tool
 * API, and both schema documents state them at the top.
 *
 * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Operation {

  /**
   * Constructs an Operation attribute.
   *
   * @param string $id
   *   Machine name, unique within the resource or structure. Becomes the
   *   context's id. A clash between two modules is a build error naming
   *   both.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param class-string<\Drupal\data_surface\Resource\DefinesInputsInterface>|null $of
   *   The resource or structure this is an operation of. Required
   *   anywhere but on that class itself.
   * @param string|null $permission
   *   The static part of access: what an account needs regardless of
   *   subject. A `%key` placeholder is filled from the identity the
   *   context knows. Access that depends on the subject is answered at
   *   run time by the access class it names, not declared here.
   * @param bool $default
   *   Whether this is the operation a child is invoked through when it
   *   is attached and neither its parent's context names one of its
   *   operations nor one of them matches the parent context's id. At most
   *   one operation of a class says so; two are refused when it is built.
   *   Meaningless for a resource asked on its own.
   * @param bool $destructive
   *   Whether a call may destroy what is there: delete it, or overwrite
   *   it beyond what was sent. A client asks before it calls one. Not one
   *   of the module's own operations is: an edit changes the keys it is
   *   sent, and a key left out keeps its value.
   * @param bool $readOnly
   *   Whether a call changes nothing at all. A read tool is read only by
   *   what it is, and needs no operation to say so.
   * @param bool|null $idempotent
   *   Whether the same call twice leaves what the first left, or NULL to
   *   have it derived: an operation that does not create is idempotent,
   *   the same document twice writing the same state, and one that
   *   creates is not, the second call finding what the first made. A read
   *   only operation is idempotent. Declared only where an operation
   *   knows better, such as one that appends.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly ?string $of = NULL,
    public readonly ?string $permission = NULL,
    public readonly bool $default = FALSE,
    public readonly bool $destructive = FALSE,
    public readonly bool $readOnly = FALSE,
    public readonly ?bool $idempotent = NULL,
  ) {}

}
