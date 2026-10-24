<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\Core\Url;
use Drupal\data_surface\ResourceBuild\CatalogDoorsInterface;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\SiteCatalog;

/**
 * The HTTP path an operation is run at, and its React page.
 *
 * An operation that can be asked on its own is served: its run is the
 * method and the path ServedPaths writes for it, each identity segment
 * a `{parameter}` (a listing's, GET on the collection), and its page is
 * `/surface-react/{resource}/{operation}`.
 */
final class ServedDoors implements CatalogDoorsInterface {

  /**
   * Constructs a ServedDoors.
   *
   * @param \Drupal\data_surface_react\ServedPaths $paths
   *   The API's paths.
   */
  public function __construct(
    protected readonly ServedPaths $paths,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    if (!$standalone) {
      return [];
    }
    // A listing is run by GET on the collection, its query the values.
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    [$method, $named] = $this->paths->listing($resource) === $operation->id ? ['GET', FALSE] : $this->paths->writes($resource, $operation);
    [$path] = $this->paths->template($resource, $operation, $named);
    $page = Url::fromRoute('data_surface_react.page', ['resource' => $resource->id, 'operation' => $operation->id])->toString();
    return [
      SiteCatalog::HTTP => $method . ' ' . $path,
      SiteCatalog::REACT => str_ireplace('%3A', ':', $page),
    ];
  }

}
