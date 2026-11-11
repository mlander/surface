<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * Names the doors one operation reaches on this site.
 *
 * A door is a way a caller asks for the operation: the form route a
 * module declares, the HTTP path under contract 3, the tool and the
 * narrowing tool, the OpenAPI operation, the React page. Each module that
 * opens doors implements this as a service tagged `surface.catalog_doors`,
 * and the site catalog (`drush surface:catalog`) asks every one, so a
 * door is listed only where the module that opens it is installed. Each
 * names its doors by keys of its own, a constant of the class
 * (FormRouteDoors::DOOR, `form`).
 *
 * The catalog lists the providers by the tag's priority, higher first,
 * and a provider's own doors in the order it gives them. The shipped
 * doors use form 40 (the base module), http 30 (surface_http), tool 20
 * and then the narrowing tool (the base module, with the Tool API
 * module), openapi 10 (surface_openapi) and react 5
 * (surface_examples_react). A provider with no priority is 0, listed
 * after them all.
 *
 * @see \Drupal\surface\ResourceBuild\SiteCatalog
 */
interface CatalogDoorsInterface {

  /**
   * Names the doors an operation reaches.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param bool $standalone
   *   Whether it can be asked on its own (ResourceCatalog::standalone()).
   *
   * @return array<string, string>
   *   What each door is, by the door's name (the provider's own key):
   *   a path, a method and a path, or an id.
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone): array;

}
