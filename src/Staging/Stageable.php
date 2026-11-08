<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationParameter;
use Drupal\surface\ResourceBuild\ResourceDefinition;

/**
 * Says where a stage applies: the one rule every door asks.
 *
 * A stage applies to a call when two things hold:
 *
 * - the resource's declared storage class implements
 *   TransactionalStorageInterface, so the transaction apply opens undoes
 *   its writes whole;
 * - the call names a thing: it creates, or its resource declares no
 *   identity keys (a settings object, one thing), or its parameters name
 *   at least one of them. An operation over several things of a
 *   resource with identity, such as the venues' reorder, names none.
 *
 * A read-only operation other than the read of one thing writes nothing
 * and reads no one thing, so a stage does not apply to it either.
 *
 * The rule is asked at each of these places, each of which takes a
 * stage where it answers TRUE, and where it answers FALSE offers none
 * or refuses one (`does_not_apply` at the doors and prepare; the flow
 * refuses its route):
 *
 * - the tool's input, `stage` (OperationInputs);
 * - the OpenAPI document, which references the `SurfaceStage` header;
 * - the HTTP door, which honors the header (ResourceApiController);
 * - the operation form and a React page served at a module's route,
 *   which read `?stage` in their query (OperationRoute::stageRequested());
 * - the React page's access check (ServedOperations::stageable());
 * - the flow's own check (Flow::refusal());
 * - the staged storage's prepare, through storage() and namesThing().
 *
 * Prepare alone also checks that the storage of each routed child is
 * transactional, and refuses the write `does_not_apply` where one is not:
 * the other places still offer the stage to such a resource.
 *
 * Decision: see
 * docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
 *
 * @internal
 */
final class Stageable {

  /**
   * Answers whether a stage may hold a write of a storage class.
   *
   * @param string|null $class
   *   The storage class, or NULL for a resource that names none.
   *
   * @return bool
   *   TRUE when the class implements TransactionalStorageInterface.
   */
  public static function storage(?string $class): bool {
    return $class !== NULL && is_a($class, TransactionalStorageInterface::class, TRUE);
  }

  /**
   * Answers whether a call names a thing a stage can hold a write of.
   *
   * @param string[] $identity
   *   The resource's identity keys.
   * @param string[] $parameters
   *   The names of the operation's parameters.
   * @param bool $creates
   *   Whether the call creates.
   *
   * @return bool
   *   FALSE for a call that does not create, of a resource with identity
   *   keys, whose parameters name none of them.
   */
  public static function namesThing(array $identity, array $parameters, bool $creates): bool {
    return $creates || $identity === [] || array_intersect($identity, $parameters) !== [];
  }

  /**
   * Answers whether a stage applies to an operation of a resource.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return bool
   *   TRUE when its storage can be staged and the operation names one
   *   thing, writes or is the read of one thing.
   */
  public static function operation(ResourceDefinition $resource, OperationDefinition $operation): bool {
    if (!self::storage($resource->storage) || ($operation->readOnly && !$operation->readsOne())) {
      return FALSE;
    }
    $parameters = array_map(static fn (OperationParameter $parameter): string => $parameter->name, $operation->parameters);
    return self::namesThing($resource->identity, $parameters, $operation->creates);
  }

}
