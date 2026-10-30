<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\Routing\Route;

/**
 * The routes modules declare for an operation: forms, and React pages.
 *
 * A route served by an operation names the resource and the operation in
 * its defaults (OperationRoute); this reads every route once and keeps
 * which ones name which operation, the resource named by its class, by
 * its id, or by an id a variable of the route's path fills
 * (`entity.content:node.{node_type}`), which is listed under every id
 * discovery found that it fills, its path filled for each. A route is
 * named with what it says of the operation's keys: the parameters its
 * plain defaults pin, what it fixes and fills, and the keys a link may
 * start. The link is the first such route a URL needs nothing more for:
 * each variable of its path filled from the resource's id or given a
 * default.
 *
 * The form door is every such route the operation form serves. A route
 * whose controller is the React page's (OperationRoute::reactPage()) is
 * no form: data_surface_react's React door lists it beside the
 * operation's own page (reactPages(), reactPageLink()).
 */
final class FormRouteDoors implements CatalogDoorLinksInterface {

  /**
   * The routes, by the resource default as written, then operation id.
   *
   * @var array<string, array<string, array<string, \Symfony\Component\Routing\Route>>>|null
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
    $found = $this->lines($resource, $operation, FALSE);
    return $found === [] ? [] : [SiteCatalog::FORM => implode(', ', $found)];
  }

  /**
   * {@inheritdoc}
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    $link = $this->linked($resource, $operation, FALSE);
    return $link === NULL ? [] : [SiteCatalog::FORM => $link];
  }

  /**
   * Names the React pages modules serve one operation of a resource at.
   *
   * Each route whose controller is ResourceReactPage::served(), named as
   * the form door names a form route: the React door lists them beside
   * the operation's own page.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return string[]
   *   Each route, as the catalog lists it (described()).
   */
  public function reactPages(ResourceDefinition $resource, OperationDefinition $operation): array {
    return $this->lines($resource, $operation, TRUE);
  }

  /**
   * Gives the first React page a module serves an operation at, as a URL.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return \Drupal\Core\Url|null
   *   The first such route a URL needs nothing more for, or NULL when no
   *   one URL is any of them.
   */
  public function reactPageLink(ResourceDefinition $resource, OperationDefinition $operation): ?Url {
    return $this->linked($resource, $operation, TRUE);
  }

  /**
   * Names each route of one kind that serves an operation.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param bool $react
   *   TRUE for the React pages modules serve it at, FALSE for the
   *   operation form's routes.
   *
   * @return string[]
   *   Each route, as the catalog lists it.
   */
  protected function lines(ResourceDefinition $resource, OperationDefinition $operation, bool $react): array {
    $found = [];
    foreach ($this->served($resource, $operation) as $name => [$route, $filled]) {
      if (OperationRoute::reactPage($route) === $react) {
        $found[] = static::described($name, $route, $filled, $operation);
      }
    }
    return $found;
  }

  /**
   * Gives the first route of one kind serving an operation that has a URL.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param bool $react
   *   TRUE for the React pages modules serve it at, FALSE for the
   *   operation form's routes.
   *
   * @return \Drupal\Core\Url|null
   *   The first one whose every variable the resource's id fills or a
   *   default gives, or NULL.
   */
  protected function linked(ResourceDefinition $resource, OperationDefinition $operation, bool $react): ?Url {
    foreach ($this->served($resource, $operation) as $name => [$route, $filled]) {
      if (OperationRoute::reactPage($route) !== $react) {
        continue;
      }
      $free = array_filter(
        $route->compile()->getVariables(),
        static fn (string $variable): bool => !array_key_exists($variable, $filled) && !$route->hasDefault($variable),
      );
      if ($free === []) {
        return Url::fromRoute($name, $filled);
      }
    }
    return NULL;
  }

  /**
   * Lists the routes that serve one operation of a resource.
   *
   * Forms and React pages alike, as the catalog judges each of them
   * (SiteCatalog::refused()).
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return array<string, array{0: \Symfony\Component\Routing\Route, 1: array<string, string>}>
   *   Each route, by name, and the raw value of each variable of its path
   *   the resource's id fills, by variable: the routes naming the resource
   *   by its class or its id first, then those whose path fills its id.
   */
  public function served(ResourceDefinition $resource, OperationDefinition $operation): array {
    $routes = $this->routes();
    $served = [];
    foreach (array_unique([$resource->name(), $resource->id]) as $named) {
      foreach ($routes[$named][$operation->id] ?? [] as $name => $route) {
        $served[$name] ??= [$route, []];
      }
    }
    foreach ($routes as $named => $operations) {
      if (!preg_match(OperationRoute::PLACEHOLDER, (string) $named)) {
        continue;
      }
      foreach ($operations[$operation->id] ?? [] as $name => $route) {
        $filled = static::filled((string) $named, $route, $resource->id);
        if ($filled !== NULL) {
          $served[$name] ??= [$route, $filled];
        }
      }
    }
    return $served;
  }

  /**
   * Reads which routes name which operation.
   *
   * @return array<string, array<string, array<string, \Symfony\Component\Routing\Route>>>
   *   Each route, by its name, by the resource default as written, then
   *   the operation.
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
      $this->routes[$resource][$operation][(string) $name] = $route;
    }
    return $this->routes;
  }

  /**
   * Reads the variables a resource's id fills, by a route's id with `{name}`.
   *
   * The id is matched against the route's, each `{name}` read as its
   * variable's requirement allows, or as any one path segment when a
   * converter bounds it, the rest as written.
   *
   * @param string $template
   *   The route's resource default, holding `{name}` once or more.
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $id
   *   An id discovery found.
   *
   * @return array<string, string>|null
   *   The raw value of each variable, by variable; NULL when the route's
   *   id cannot be filled to this one.
   */
  protected static function filled(string $template, Route $route, string $id): ?array {
    $parts = preg_split(OperationRoute::PLACEHOLDER, $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $pattern = '';
    $variables = [];
    foreach ($parts as $delta => $part) {
      if ($delta % 2 === 0) {
        $pattern .= preg_quote($part);
      }
      elseif (isset($variables[$part])) {
        $pattern .= '(?P=' . $part . ')';
      }
      else {
        $variables[$part] = TRUE;
        $pattern .= '(?P<' . $part . '>' . ($route->getRequirement($part) ?? '[^/]+') . ')';
      }
    }
    if (preg_match('{^' . $pattern . '$}sD', $id, $match) !== 1) {
      return NULL;
    }
    return array_map('strval', array_intersect_key($match, $variables));
  }

  /**
   * Names a route with what it says of the operation's keys.
   *
   * `<path> (<name>)`, the path filled where the resource's id fills it,
   * then in brackets what the route says beside the two naming defaults:
   * each parameter of the operation a plain default pins, each key it
   * fixes or fills with the value as written (a reference as `{name}`),
   * and each key a link may start with the query parameter it reads.
   *
   * @param string $name
   *   The route's name.
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param array<string, string> $filled
   *   The raw value of each variable the resource's id fills.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation it serves.
   *
   * @return string
   *   The route, as the catalog lists it.
   */
  protected static function described(string $name, Route $route, array $filled, OperationDefinition $operation): string {
    $path = $route->getPath();
    foreach ($filled as $variable => $value) {
      $path = str_replace('{' . $variable . '}', $value, $path);
    }
    $parameters = array_map(static fn (OperationParameter $parameter): string => $parameter->name, $operation->parameters);
    $variables = $route->compile()->getVariables();
    $pins = [];
    foreach ($route->getDefaults() as $default => $value) {
      $default = (string) $default;
      if (!str_starts_with($default, '_') && in_array($default, $parameters, TRUE) && !in_array($default, $variables, TRUE)) {
        $pins[] = $default . ': ' . static::written($value);
      }
    }
    $said = $pins === [] ? [] : ['pins ' . implode(', ', $pins)];
    foreach (['fixes' => OperationRoute::FIXED, 'fills' => OperationRoute::DEFAULTS] as $verb => $default) {
      $map = $route->getDefault($default);
      if (is_array($map) && $map !== []) {
        $said[] = $verb . ' ' . implode(', ', array_map(
          static fn (string|int $path, mixed $value): string => $path . ': ' . static::written($value),
          array_keys($map),
          $map,
        ));
      }
    }
    $query = $route->getDefault(OperationRoute::QUERY);
    if (is_array($query) && $query !== []) {
      $said[] = 'starts ' . implode(', ', array_map(
        static fn (string|int $path, mixed $parameter): string => $path . ' from ?' . static::written($parameter),
        array_keys($query),
        $query,
      ));
    }
    return $path . ' (' . $name . ')' . ($said === [] ? '' : ' [' . implode('; ', $said) . ']');
  }

  /**
   * Writes a value a route carries as the catalog shows it.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Text as written, anything else as JSON.
   */
  protected static function written(mixed $value): string {
    return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

}
