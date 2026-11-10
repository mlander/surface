<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * What a flow class declares its steps into (FlowInterface::defineSteps()).
 *
 * Experimental, as every flow is.
 *
 * @see docs/staging.md#flows
 */
interface StepsInterface {

  /**
   * Adds a step, drawn after the steps added before it.
   *
   * The first step is the route's own operation. A later step over the
   * same resource and operation, with no arguments, writes through the
   * route's operation too; any other is a step over another resource,
   * which writes through its own operation into the same stage.
   *
   * @param string $id
   *   The step's id, distinct among the flow's steps.
   * @param string $resource
   *   The resource's id or class.
   * @param string $operation
   *   One of its operations.
   * @param array<array-key, mixed> $arguments
   *   Literal values for the operation's parameters, by name. None on the
   *   first step: the route's path parameters, or its plain defaults,
   *   supply the route operation's.
   *
   * @return \Drupal\surface\Flow\StepInterface
   *   The step, which says what it draws.
   *
   * @throws \InvalidArgumentException
   *   When the id is taken, the id, resource or operation is empty, or
   *   the arguments are a list rather than keyed by name.
   */
  public function step(string $id, string $resource, string $operation, array $arguments = []): StepInterface;

}
