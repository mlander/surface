<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;

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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   What discovery found about the resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array $parameters
   *   The operation's parameters as the request gave them, by name: raw
   *   ids and scalars, never an upcast entity.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context the operation built from them.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The built resource, in that context.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface|null $storage
   *   The storage composed for it, or NULL when the resource names none.
   */
  public function __construct(
    public readonly ResourceDefinition $definition,
    public readonly OperationDefinition $operation,
    public readonly array $parameters,
    public readonly OperationContext $context,
    public readonly DataSurfaceInterface $surface,
    public readonly ?DataSurfaceStorageInterface $storage,
  ) {}

}
