<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Names one operation: one way a resource or structure is asked for.
 *
 * Its static facts are here, read at discovery without running anything:
 * the id, the label, a description, the permission, whether it creates,
 * and how a call behaves. Two places hold one:
 *
 * - **The class**, for an operation whose context says nothing more than
 *   those facts: `#[Operation('configure', label: ..., permission: ...)]`
 *   beside `#[Resource]`, repeatable, and no method at all. Its context
 *   is `new OperationContext()` stamped with the id and `creates`. This
 *   is the default.
 * - **A static method returning an OperationContext**, for one whose
 *   context needs more: parameters (what the operation needs is the
 *   method's own signature, read by reflection), identity it knows, a
 *   constraint, defaults or a child. The context may leave its id and
 *   `creates` out; the attribute's are stamped on it. One that names a
 *   different id, or says otherwise about creating, is refused: at
 *   discovery when the method needs nothing, else when it is first asked.
 *
 * Found by attribute, like a hook method, on two kinds of class:
 *   - the resource's or structure's own class, with no `of`;
 *   - any class in src/Alter, with `of` naming that class.
 * So another module can add a way to ask for a resource it does not
 * own, usually by building on one of the owner's operations.
 *
 * With this, an operation can be listed, addressed and generated from:
 *   - a discovery document lists every resource with its operations;
 *   - a tool exists per operation, `field.instance:edit`, its inputs
 *     being the method's parameters plus the resource's open keys, its
 *     description this one's;
 *   - a route maps its parameters onto one operation;
 *   - the context's id is the operation id, nothing else.
 *
 * It also states how a call behaves, for a client deciding whether to
 * ask before calling and whether a retry is safe: whether it creates,
 * whether it changes nothing, whether it may destroy what is there, and
 * whether the same call twice leaves what the first left. A tool carries
 * them to the Tool API, and both schema documents state them at the top.
 *
 * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
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
   *   knows better: one that appends is not, and a create whose caller
   *   names the thing is, a second identical call being refused for the
   *   name it took.
   * @param bool $creates
   *   Whether submitting makes a new thing rather than changing one that
   *   exists. Stamped on the context, which may say so too and must then
   *   agree. It decides the operation's kind: a create's tool requires
   *   the keys the resource requires, an update's requires none.
   * @param string|\Stringable|null $description
   *   One or two sentences for whoever chooses between operations, an
   *   agent among them: what a call does to what. The first sentence of
   *   the tool's description and the top `description` of both schema
   *   documents. NULL takes the method's docblock summary, for the method
   *   form; the class form then has none.
   * @param bool $deletes
   *   Whether a call removes the thing it names: it takes no values, only
   *   the parameters that name the thing, and its kind is a delete. Such
   *   an operation says destructive: TRUE too, and neither creates nor is
   *   read only; the facts are refused at discovery otherwise. Its tool
   *   takes the parameters and a dry run, and HTTP serves it as DELETE.
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
    public readonly bool $creates = FALSE,
    public readonly string|\Stringable|null $description = NULL,
    public readonly bool $deletes = FALSE,
  ) {}

}
