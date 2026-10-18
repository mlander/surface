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
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly ?string $of = NULL,
    public readonly ?string $permission = NULL,
    public readonly bool $default = FALSE,
  ) {}

}
