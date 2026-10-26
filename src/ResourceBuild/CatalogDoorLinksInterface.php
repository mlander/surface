<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * Gives the URL of each door one operation reaches, where it has one.
 *
 * The catalog page links a door its module can give a URL for: a form
 * route, the HTTP path, the React page, the OpenAPI operation in the
 * document's reference. A door provider that implements this beside
 * CatalogDoorsInterface answers for the doors it names; a door no
 * provider links, a tool id, is shown as text.
 *
 * @see \Drupal\data_surface\ResourceBuild\SiteCatalog::links()
 */
interface CatalogDoorLinksInterface extends CatalogDoorsInterface {

  /**
   * Gives the URL of each door this names for an operation.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param bool $standalone
   *   Whether it can be asked on its own (ResourceCatalog::standalone()).
   * @param bool $reads
   *   Whether what it changes can be read on its own.
   *
   * @return array<string, \Drupal\Core\Url>
   *   By the door's name (one of SiteCatalog::DOORS), each door with a
   *   URL; a door whose path holds a parameter is left out, since no one
   *   URL is it.
   */
  public function links(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array;

}
