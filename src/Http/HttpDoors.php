<?php

declare(strict_types=1);

namespace Drupal\surface\Http;

use Drupal\Core\Url;
use Drupal\surface\ResourceBuild\CatalogDoorLinksInterface;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\SiteCatalog;

/**
 * The HTTP path an operation is run at.
 *
 * An operation that can be asked on its own is served: its run is the
 * method and the path ServedPaths writes for it, each identity segment
 * a `{parameter}` (a listing's, GET on the collection). The path is
 * linked where it names no parameter.
 */
final class HttpDoors implements CatalogDoorLinksInterface {

  /**
   * Constructs an HttpDoors.
   *
   * @param \Drupal\surface\Http\ServedPaths $paths
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
    [$method, $named] = $this->run($resource, $operation);
    [$path] = $this->paths->template($resource, $operation, $named);
    return [SiteCatalog::HTTP => $method . ' ' . $path];
  }

  /**
   * {@inheritdoc}
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    if (!$standalone) {
      return [];
    }
    [, $named] = $this->run($resource, $operation);
    [, $parameters] = $this->paths->template($resource, $operation, $named);
    if ($parameters !== []) {
      return [];
    }
    // The API writes its own colons, so its URL is taken as written.
    return [SiteCatalog::HTTP => Url::fromUri($this->paths->url($resource, $operation, [], $named, FALSE, ['absolute' => TRUE])->getGeneratedUrl())];
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

}
