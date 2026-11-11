<?php

declare(strict_types=1);

namespace Drupal\surface_http;

use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\Staging\Stage;
use Drupal\surface\SurfaceInterface;

/**
 * One operation of one resource, as a request named it, built and bound.
 *
 * What the page and the endpoints start from: a prepare the runner
 * answered (OperationRunnerInterface::run() with no values), and the
 * parameters the request carried. Its properties are read off the run:
 * the resource and the operation, the context the operation built from
 * the parameters, the resource built in that context, the storage
 * composed for it, and the stage the request named. Asked afresh on
 * every request, as the operation form asks afresh at every stage.
 *
 * @internal
 */
final class ServedOperation {

  /**
   * What discovery found about the resource.
   */
  public readonly ResourceDefinition $definition;

  /**
   * The operation.
   */
  public readonly OperationDefinition $operation;

  /**
   * The context the operation built from the parameters.
   */
  public readonly OperationContext $context;

  /**
   * The built resource, in that context.
   */
  public readonly SurfaceInterface $surface;

  /**
   * The storage composed for it, or NULL when the resource names none.
   */
  public readonly ?SurfaceStorageInterface $storage;

  /**
   * The stage the request goes through, or NULL for none.
   *
   * It built the context and was handed the storage.
   */
  public readonly ?Stage $stage;

  /**
   * Constructs a ServedOperation.
   *
   * @param \Drupal\surface\Operation\OperationRun $run
   *   The prepare the runner answered: built, not refused, not run.
   * @param array $parameters
   *   The operation's parameters as the request gave them, by name: raw
   *   ids and scalars, never an entity.
   */
  public function __construct(
    public readonly OperationRun $run,
    public readonly array $parameters,
  ) {
    $this->definition = $run->definition();
    $this->operation = $run->operation();
    $this->context = $run->context();
    $this->surface = $run->surface();
    $this->storage = $run->hasStorage() ? $run->storage() : NULL;
    $this->stage = $run->stage();
  }

}
