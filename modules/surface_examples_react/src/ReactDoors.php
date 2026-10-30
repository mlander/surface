<?php

declare(strict_types=1);

namespace Drupal\surface_examples_react;

use Drupal\Core\Url;
use Drupal\surface\Http\HttpDoors;
use Drupal\surface\ResourceBuild\CatalogDoorLinksInterface;
use Drupal\surface\ResourceBuild\FormRouteDoors;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\SiteCatalog;

/**
 * The React pages an operation's form mounts on.
 *
 * An operation the HTTP door serves has a page of its own,
 * `/surface-react/{resource}/{operation}`, whose app reads and writes at
 * the operation's path, so the page is listed wherever the HTTP door
 * names that path (HttpDoors), and linked. A module may serve an
 * operation's React page at a route of its own
 * (ResourceReactPage::served()): each such route is listed after the
 * page, named as the form door names a form route (FormRouteDoors), and
 * linked where the operation has no page of its own.
 */
final class ReactDoors implements CatalogDoorLinksInterface {

  /**
   * Constructs a ReactDoors.
   *
   * @param \Drupal\surface\Http\HttpDoors $http
   *   The HTTP door's listing, which says whether the API serves the
   *   operation the page's app calls.
   * @param \Drupal\surface\ResourceBuild\FormRouteDoors $routes
   *   The routes modules declare for an operation, the React pages among
   *   them.
   */
  public function __construct(
    protected readonly HttpDoors $http,
    protected readonly FormRouteDoors $routes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    // The React pages modules serve the operation at, each with what its
    // route says of the keys.
    $pages = $this->routes->reactPages($resource, $operation);
    if (!$this->served($resource, $operation, $standalone, $reads)) {
      return $pages === [] ? [] : [SiteCatalog::REACT => implode(', ', $pages)];
    }
    $own = str_ireplace('%3A', ':', $this->page($resource, $operation)->toString());
    return [SiteCatalog::REACT => implode(', ', [$own, ...$pages])];
  }

  /**
   * {@inheritdoc}
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    if (!$this->served($resource, $operation, $standalone, $reads)) {
      $page = $this->routes->reactPageLink($resource, $operation);
      return $page === NULL ? [] : [SiteCatalog::REACT => $page];
    }
    return [SiteCatalog::REACT => $this->page($resource, $operation)];
  }

  /**
   * Answers whether the HTTP door serves an operation, so it has a page.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $standalone
   *   Whether it can be asked on its own.
   * @param bool $reads
   *   Whether what it changes can be read on its own.
   *
   * @return bool
   *   TRUE when the HTTP door names its path.
   *
   * @throws \Throwable
   *   What the HTTP door throws for an operation it cannot name a path
   *   for, so the catalog leaves this door out with that one.
   */
  protected function served(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): bool {
    return $this->http->doors($resource, $operation, $standalone, $reads) !== [];
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
    return Url::fromRoute('surface_examples_react.page', ['resource' => $resource->id, 'operation' => $operation->id]);
  }

}
