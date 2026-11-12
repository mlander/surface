<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * Names the paths an operation is served at over HTTP, where it is.
 *
 * The main module's other doors write these into what they say: the tool
 * names the path its full schema is fetched at and the lookup of a key
 * too long to list, and the contract panel names the document the HTTP
 * door serves. One module provides it: surface_http, as the service
 * `Drupal\surface\ResourceBuild\ServedPathsInterface`. On a site without
 * that module the container has no such service, and those doors name no
 * path. Its methods take ids, not definitions, so an implementation
 * depends on nothing the module keeps internal; it looks the definitions
 * up itself.
 *
 * Decision: see
 * docs/decisions.md#the-http-door-and-the-openapi-document-are-their-own-modules.
 */
interface ServedPathsInterface {

  /**
   * Names the path an operation's full schema is fetched at.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   *
   * @return string
   *   The path, each identity segment a `{parameter}` and each other
   *   parameter the operation takes in the query, `?name={name}`.
   */
  public function schemaPath(string $resource, string $operation): string;

  /**
   * Names the path of the resource's lookup for an operation.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id, whose identity parameters the path names.
   *
   * @return string|null
   *   The path, each identity segment a `{parameter}`; NULL for an
   *   operation `GET` does not serve at its path, since the lookup answers
   *   for the operation it serves there.
   */
  public function lookupPath(string $resource, string $operation): ?string;

}
