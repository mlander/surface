<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * Names the doors one operation reaches on this site.
 *
 * A door is a way a caller asks for the operation: the form route a
 * module declares, the HTTP path under contract 3, the tool and the read
 * tool, the OpenAPI operation, the React page. Each module that opens
 * doors implements this as a service tagged `data_surface.catalog_doors`,
 * and the site catalog (`drush surface:catalog`) asks every one, so a
 * door is listed only where the module that opens it is installed.
 *
 * @see \Drupal\data_surface\ResourceBuild\SiteCatalog
 */
interface CatalogDoorsInterface {

  /**
   * Names the doors an operation reaches.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param bool $standalone
   *   Whether it can be asked on its own (ResourceCatalog::standalone()).
   * @param bool $reads
   *   Whether what it changes can be read on its own
   *   (ResourceCatalog::reads()).
   *
   * @return array<string, string>
   *   What each door is, by the door's name (one of SiteCatalog::DOORS):
   *   a path, a method and a path, or an id.
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array;

}
