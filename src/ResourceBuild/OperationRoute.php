<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\data_surface\Resource\OperationContext;
use Symfony\Component\Routing\Route;

/**
 * A route served by one operation of one resource, read off the route.
 *
 * The route names the resource class and the operation; its parameters
 * are mapped onto the operation method's parameters by name, so an
 * upcast entity parameter arrives as the entity and a plain one as its
 * value. The same reading serves the route's access check and the form
 * the route builds, so the two cannot disagree about where they are.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
 *     _data_surface_resource: 'Drupal\example\Resource\ExampleResource'
 *     _data_surface_operation: 'update'
 *   options:
 *     parameters:
 *       example:
 *         type: 'entity:example'
 * @endcode
 *
 * @internal
 */
final class OperationRoute {

  /**
   * The route default naming the resource class, or its id.
   */
  public const RESOURCE = '_data_surface_resource';

  /**
   * The route default naming the operation.
   */
  public const OPERATION = '_data_surface_operation';

  /**
   * The route requirement that gates the route by the operation.
   *
   * Added to every route naming a resource and an operation
   * (OperationRouteSubscriber); a routing file may still say it.
   */
  public const ACCESS = '_data_surface_operation_access';

  /**
   * Constructs an OperationRoute.
   *
   * @param string $resource
   *   The resource's class, or a derived resource's id.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context the operation built from the route's parameters.
   * @param string|null $subject
   *   The raw value of the operation's first parameter the route carries,
   *   for presentation: what a cosmetic layer is told the form is about.
   */
  public function __construct(
    public readonly string $resource,
    public readonly OperationDefinition $operation,
    public readonly OperationContext $context,
    public readonly ?string $subject,
  ) {}

  /**
   * Answers whether a route is served by an operation.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when it names a resource.
   */
  public static function serves(?Route $route): bool {
    return (string) ($route?->getDefault(self::RESOURCE) ?? '') !== '';
  }

  /**
   * Reads the operation a route is served by, and builds its context.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\ResourceBuild\OperationArguments $arguments
   *   What maps the route's parameters onto the operation's.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which invokes the operation.
   *
   * @return self
   *   The operation, and the context it built.
   *
   * @throws \LogicException
   *   When the route names no resource or no operation.
   * @throws \InvalidArgumentException
   *   When the resource or operation is not discovered, or a parameter the
   *   operation needs is not on the route or names nothing.
   */
  public static function fromRouteMatch(RouteMatchInterface $route_match, ResourceRegistry $registry, OperationArguments $arguments, ResourcesInterface $resources): self {
    $route = $route_match->getRouteObject();
    $route_resource = (string) ($route?->getDefault(self::RESOURCE) ?? '');
    $id = (string) ($route?->getDefault(self::OPERATION) ?? '');
    if ($route_resource === '' || $id === '') {
      throw new \LogicException(sprintf(
        'The %s route is served by an operation and has to name the resource in "%s" and the operation in "%s".',
        (string) $route_match->getRouteName(),
        self::RESOURCE,
        self::OPERATION,
      ));
    }
    $class = $registry->getDefinition($route_resource)->name();
    $operation = $registry->getOperation($class, $id);
    $given = $arguments->fromRoute($operation, $route_match);
    $subject = NULL;
    foreach ($operation->parameters as $parameter) {
      $raw = $route_match->getRawParameter($parameter->name);
      if ($raw !== NULL) {
        $subject = (string) $raw;
        break;
      }
    }
    return new self($class, $operation, $resources->operation($class, $id, $given), $subject);
  }

}
