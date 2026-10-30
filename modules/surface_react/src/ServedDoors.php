<?php

declare(strict_types=1);

namespace Drupal\surface_react;

use Drupal\Core\Url;
use Drupal\surface\ResourceBuild\CatalogDoorLinksInterface;
use Drupal\surface\ResourceBuild\FormRouteDoors;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\SiteCatalog;

/**
 * The HTTP path an operation is run at, and its React pages.
 *
 * An operation that can be asked on its own is served: its run is the
 * method and the path ServedPaths writes for it, each identity segment
 * a `{parameter}` (a listing's, GET on the collection), and its page is
 * `/surface-react/{resource}/{operation}`. Both are linked, the path
 * where it names no parameter. A module may serve an operation's React
 * page at a route of its own (ResourceReactPage::served()): each such
 * route is listed beside the page, named as the form door names a form
 * route (FormRouteDoors), and linked where the operation has no page of
 * its own.
 */
final class ServedDoors implements CatalogDoorLinksInterface {

  /**
   * Constructs a ServedDoors.
   *
   * @param \Drupal\surface_react\ServedPaths $paths
   *   The API's paths.
   * @param \Drupal\surface\ResourceBuild\FormRouteDoors $routes
   *   The routes modules declare for an operation, the React pages among
   *   them.
   */
  public function __construct(
    protected readonly ServedPaths $paths,
    protected readonly FormRouteDoors $routes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    // The React pages modules serve the operation at, each with what its
    // route says of the keys.
    $pages = $this->routes->reactPages($resource, $operation);
    if (!$standalone) {
      return $pages === [] ? [] : [SiteCatalog::REACT => implode(', ', $pages)];
    }
    // A listing is run by GET on the collection, its query the values.
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    [$method, $named] = $this->run($resource, $operation);
    [$path] = $this->paths->template($resource, $operation, $named);
    $own = str_ireplace('%3A', ':', $this->page($resource, $operation)->toString());
    return [
      SiteCatalog::HTTP => $method . ' ' . $path,
      SiteCatalog::REACT => implode(', ', [$own, ...$pages]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    if (!$standalone) {
      $page = $this->routes->reactPageLink($resource, $operation);
      return $page === NULL ? [] : [SiteCatalog::REACT => $page];
    }
    [, $named] = $this->run($resource, $operation);
    [, $parameters] = $this->paths->template($resource, $operation, $named);
    return array_filter([
      // The API writes its own colons, so its URL is taken as written.
      SiteCatalog::HTTP => $parameters === [] ? Url::fromUri($this->paths->url($resource, $operation, [], $named, FALSE, ['absolute' => TRUE])->getGeneratedUrl()) : NULL,
      SiteCatalog::REACT => $this->page($resource, $operation),
    ]);
  }

  /**
   * Says how an operation is run: its method, and whether its path names it.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array{0: string, 1: bool}
   *   The method, and whether `:{operation}` ends the path.
   */
  protected function run(ResourceDefinition $resource, OperationDefinition $operation): array {
    return $this->paths->listing($resource) === $operation->id ? ['GET', FALSE] : $this->paths->writes($resource, $operation);
  }

  /**
   * Gives the React page of an operation.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\Url
   *   The page.
   */
  protected function page(ResourceDefinition $resource, OperationDefinition $operation): Url {
    return Url::fromRoute('surface_react.page', ['resource' => $resource->id, 'operation' => $operation->id]);
  }

}
