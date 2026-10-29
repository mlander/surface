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
 * A route may also fill or fix what the operation asks for, on this page
 * alone: `_data_surface_fixed` narrows each key it names to one value
 * (OperationContext::withConstraint() with a Choice of it that says it
 * fixes the key, Declaring::FIXED), and
 * `_data_surface_defaults` gives each key it names the value it starts
 * from (OperationContext::withDefaults(), on an operation that creates).
 * Both are maps of key paths to values, applied to the context the
 * operation built, and held to the resource here: a path that names no
 * key, or a value its key refuses, is refused as a parameter that names
 * nothing is, with the route's name.
 *
 * @code
 * example.add_welcome:
 *   path: '/admin/content/add-welcome'
 *   defaults:
 *     _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
 *     _data_surface_resource: 'entity.content:node.page'
 *     _data_surface_operation: 'create'
 *     _data_surface_fixed: { 'title.value': 'Welcome' }
 *     _data_surface_defaults: { 'body.value': 'Start here.' }
 * @endcode
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
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
   * The route default fixing keys to one value each: key path => value.
   */
  public const FIXED = '_data_surface_fixed';

  /**
   * The route default giving keys the value each starts from.
   */
  public const DEFAULTS = '_data_surface_defaults';

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
   *   When the resource or operation is not discovered, a parameter the
   *   operation needs is not on the route or names nothing, or a value
   *   the route fixes or fills names no key or does not fit its key.
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
    $context = $resources->operation($class, $id, $given);
    return new self($class, $operation, static::withRouteValues($route_match, $class, $context, $resources), $subject);
  }

  /**
   * Applies what a route fixes and fills to the context its operation built.
   *
   * Held to the resource at once, by building it for no account, which
   * the build cache keeps for the next request: a refusal of the route's
   * values is the route's,
   * thrown as a parameter that names nothing is, which the access check
   * answers with a refusal saying why. A refusal the operation's own
   * context meets without the route's values stays the operation's.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $class
   *   The resource's class, or a derived resource's id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context the operation built.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The context, fixing and filling what the route says.
   *
   * @throws \InvalidArgumentException
   *   When either default is not a map of key paths, a fixed value is not
   *   one value, or the resource refuses what the route says.
   */
  protected static function withRouteValues(RouteMatchInterface $route_match, string $class, OperationContext $context, ResourcesInterface $resources): OperationContext {
    $route = $route_match->getRouteObject();
    $name = (string) $route_match->getRouteName();
    $fixed = $route?->getDefault(self::FIXED) ?? [];
    $defaults = $route?->getDefault(self::DEFAULTS) ?? [];
    if ($fixed === [] && $defaults === []) {
      return $context;
    }
    foreach ([self::FIXED => $fixed, self::DEFAULTS => $defaults] as $default => $map) {
      if (!is_array($map) || array_filter(array_keys($map), static fn (string|int $path): bool => !is_string($path) || $path === '') !== []) {
        throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" is a map of key paths to values, as { \'title.value\': \'Welcome\' }.', $name, $default));
      }
    }
    $said = $context;
    foreach ($fixed as $path => $value) {
      if (!is_scalar($value)) {
        throw new \InvalidArgumentException(sprintf('The %s route fixes "%s" to %s: a fixed value is one value of a key that holds one.', $name, $path, get_debug_type($value)));
      }
      $context = $context->withConstraint((string) $path, 'Choice', ['choices' => [$value], Declaring::FIXED => TRUE]);
    }
    if ($defaults !== []) {
      $context = $context->withDefaults($defaults);
    }
    try {
      $resources->buildDeclared($class, $context);
    }
    catch (\LogicException $e) {
      try {
        $resources->buildDeclared($class, $said);
      }
      catch (\Throwable) {
        throw $e;
      }
      throw new \InvalidArgumentException(sprintf('The %s route: %s', $name, $e->getMessage()), 0, $e);
    }
    return $context;
  }

}
