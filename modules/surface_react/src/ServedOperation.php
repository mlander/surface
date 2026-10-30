<?php

declare(strict_types=1);

namespace Drupal\surface_react;

use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;

/**
 * One operation of one resource, as a request named it, built and bound.
 *
 * What the page and the three endpoints all start from: the resource and
 * the operation the path named, the context the operation built from
 * the parameters the request carried, the resource built in that context
 * and the storage composed for it. Asked afresh on every request, as the
 * operation form asks afresh at every stage.
 */
final class ServedOperation {

  /**
   * Constructs a ServedOperation.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   What discovery found about the resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array $parameters
   *   The operation's parameters as the request gave them, by name: raw
   *   ids and scalars, never an upcast entity.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the operation built from them.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource, in that context.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface|null $storage
   *   The storage composed for it, or NULL when the resource names none.
   */
  public function __construct(
    public readonly ResourceDefinition $definition,
    public readonly OperationDefinition $operation,
    public readonly array $parameters,
    public readonly OperationContext $context,
    public readonly SurfaceInterface $surface,
    public readonly ?SurfaceStorageInterface $storage,
  ) {}

}
