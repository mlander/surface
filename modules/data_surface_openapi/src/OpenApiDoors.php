<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi;

use Drupal\data_surface\ResourceBuild\CatalogDoorsInterface;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\SiteCatalog;
use Drupal\data_surface_react\ServedPaths;

/**
 * The OpenAPI operation that runs an operation.
 *
 * The document describes every operation that can be asked on its own,
 * its run as `<resource>:<operation>:write` beside `:read`, `:schema` and
 * `:narrow`, a listing's GET on the collection as `:list`
 * (OpenApiDocument::operation()); the catalog names the run.
 */
final class OpenApiDoors implements CatalogDoorsInterface {

  /**
   * Constructs an OpenApiDoors.
   *
   * @param \Drupal\data_surface_react\ServedPaths $paths
   *   The API's paths, which say which operation is a listing.
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
    $run = $this->paths->listing($resource) === $operation->id ? 'list' : 'write';
    return [SiteCatalog::OPENAPI => $resource->id . ':' . $operation->id . ':' . $run];
  }

}
