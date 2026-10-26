<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi;

use Drupal\Core\Url;
use Drupal\data_surface\ResourceBuild\CatalogDoorLinksInterface;
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
final class OpenApiDoors implements CatalogDoorLinksInterface {

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
    return [SiteCatalog::OPENAPI => $this->operationId($resource, $operation)];
  }

  /**
   * {@inheritdoc}
   *
   * The reference over the document, at the operation: Redoc's anchor is
   * `operation/<operationId>`. The document itself is JSON, which a
   * browser shows with no anchor to land on.
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    if (!$standalone) {
      return [];
    }
    return [SiteCatalog::OPENAPI => Url::fromRoute('data_surface_openapi.reference', [], ['fragment' => 'operation/' . $this->operationId($resource, $operation)])];
  }

  /**
   * Gives the id of the OpenAPI operation that runs an operation.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string
   *   `<id>:<operation>:write`, a listing's `:list`.
   */
  protected function operationId(ResourceDefinition $resource, OperationDefinition $operation): string {
    $run = $this->paths->listing($resource) === $operation->id ? 'list' : 'write';
    return $resource->id . ':' . $operation->id . ':' . $run;
  }

}
