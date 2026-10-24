<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Routing\RouteProviderInterface;

/**
 * The form routes modules declare for an operation.
 *
 * A route served by an operation names the resource and the operation in
 * its defaults (OperationRoute); this reads every route once and keeps
 * which ones name which operation, the resource named by its class or by
 * its id.
 */
final class FormRouteDoors implements CatalogDoorsInterface {

  /**
   * The form routes, by resource name or id, then operation id.
   *
   * @var array<string, array<string, list<string>>>|null
   */
  protected ?array $routes = NULL;

  /**
   * Constructs a FormRouteDoors.
   *
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   */
  public function __construct(
    protected readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    $routes = $this->routes();
    $found = array_unique([
      ...$routes[$resource->name()][$operation->id] ?? [],
      ...$routes[$resource->id][$operation->id] ?? [],
    ]);
    return $found === [] ? [] : [SiteCatalog::FORM => implode(', ', $found)];
  }

  /**
   * Reads which form routes name which operation.
   *
   * @return array<string, array<string, list<string>>>
   *   Each route's path and name, by the resource it names, then the
   *   operation.
   */
  protected function routes(): array {
    if ($this->routes !== NULL) {
      return $this->routes;
    }
    $this->routes = [];
    foreach ($this->routeProvider->getAllRoutes() as $name => $route) {
      if (!OperationRoute::serves($route)) {
        continue;
      }
      $resource = (string) $route->getDefault(OperationRoute::RESOURCE);
      $operation = (string) $route->getDefault(OperationRoute::OPERATION);
      $this->routes[$resource][$operation][] = $route->getPath() . ' (' . $name . ')';
    }
    return $this->routes;
  }

}
