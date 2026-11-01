<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Turns what a route or a tool was given into an operation's arguments.
 *
 * An operation's needs are its own signature. A route supplies them as
 * parameters of the same name, a tool as inputs of the same name, and
 * both arrive at the same method with the same values.
 *
 * Every parameter is a value: a method's is typed with a scalar, a
 * derived operation's names a key of its resource. The operation
 * receives the value (the raw value of a route's parameter, the id of an
 * entity a PHP caller hands over), and nothing is loaded for it here:
 * loading is the storage's, which the operation asks, so a storage that
 * stands in front of another answers for what it names.
 *
 * Decision: see
 * docs/decisions.md#an-operation-is-handed-values-never-objects.
 */
final class OperationArguments {

  /**
   * Resolves the arguments for one operation.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array $arguments
   *   The arguments, by parameter name or by position: each a value, or
   *   an entity, which is named by its id.
   *
   * @return array
   *   The arguments, by parameter name, ready for the method.
   *
   * @throws \InvalidArgumentException
   *   When a parameter that has to be given is not.
   */
  public function resolve(OperationDefinition $operation, array $arguments): array {
    $resolved = [];
    foreach ($operation->parameters as $position => $parameter) {
      $given = array_key_exists($parameter->name, $arguments)
        ? [$arguments[$parameter->name]]
        : (array_key_exists($position, $arguments) ? [$arguments[$position]] : []);
      if ($given === []) {
        if (!$parameter->optional) {
          throw new \InvalidArgumentException(sprintf(
            'The "%s" operation, %s, needs %s, and it was not given.',
            $operation->id,
            $operation->describe(),
            $parameter->describe(),
          ));
        }
        continue;
      }
      // An entity a PHP caller hands over is named by its id: what the
      // operation takes is the value, which its storage loads.
      $resolved[$parameter->name] = $given[0] instanceof EntityInterface ? $given[0]->id() : $given[0];
    }
    return $resolved;
  }

  /**
   * Reads an operation's arguments off a route match.
   *
   * Each parameter is the raw value of the route parameter of the same
   * name, as the path carries it, whatever a converter made of it; a
   * plain default is its value. A parameter the route does not carry is
   * left out, which is refused by resolve() unless it is optional.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return array
   *   The arguments the route supplies, by parameter name.
   */
  public function fromRoute(OperationDefinition $operation, RouteMatchInterface $route_match): array {
    $arguments = [];
    foreach ($operation->parameters as $parameter) {
      $value = $route_match->getRawParameter($parameter->name) ?? $route_match->getParameter($parameter->name);
      if ($value !== NULL) {
        $arguments[$parameter->name] = $value;
      }
    }
    return $arguments;
  }

}
