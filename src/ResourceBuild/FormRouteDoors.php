<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;

/**
 * The form routes modules declare for an operation.
 *
 * A route served by an operation names the resource and the operation in
 * its defaults (OperationRoute); this reads every route once and keeps
 * which ones name which operation, the resource named by its class or by
 * its id. The link is the first such route whose path holds no parameter.
 */
final class FormRouteDoors implements CatalogDoorLinksInterface {

  /**
   * The form routes, by resource name or id, then operation id.
   *
   * @var array<string, array<string, list<string>>>|null
   */
  protected ?array $routes = NULL;

  /**
   * The names of the form routes with no parameter, the same way.
   *
   * @var array<string, array<string, list<string>>>
   */
  protected array $plain = [];

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
   * {@inheritdoc}
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    $this->routes();
    $plain = [
      ...$this->plain[$resource->name()][$operation->id] ?? [],
      ...$this->plain[$resource->id][$operation->id] ?? [],
    ];
    return $plain === [] ? [] : [SiteCatalog::FORM => Url::fromRoute($plain[0])];
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
      if (!str_contains($route->getPath(), '{')) {
        $this->plain[$resource][$operation][] = (string) $name;
      }
    }
    return $this->routes;
  }

}
