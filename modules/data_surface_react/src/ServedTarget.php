<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;

/**
 * What one request to the API names: a resource, an operation, an action.
 *
 * Read off the path and the method by ServedPaths::target(), before
 * anything is built: the resource the first segment names, the operation
 * the method and the operation's kind imply (or a custom method,
 * `:{operation}`, names), the operation's parameters from the path's
 * identity segments and the query string, and what is asked of it.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ServedTarget {

  /**
   * The envelope: the values stored and the schema for them (GET).
   */
  public const READ = 'read';

  /**
   * The full schema, for any values (GET .../schema).
   */
  public const SCHEMA = 'schema';

  /**
   * The schema for values sent, a query (POST .../schema).
   */
  public const NARROW = 'narrow';

  /**
   * A run of the operation: PATCH, POST, DELETE, or a custom method's run.
   */
  public const WRITE = 'write';

  /**
   * A listing's answer: GET on a collection that has a listing.
   *
   * Its query string is the values, and its outputs the answer.
   */
  public const LIST = 'list';

  /**
   * A reference's lookup: GET `.../{x}:lookup`, the candidates of one key.
   */
  public const LOOKUP = 'lookup';

  /**
   * Constructs a ServedTarget.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, string> $parameters
   *   The operation's parameters by name, as raw values: those the path's
   *   identity segments carry, then those the query string does.
   * @param string $action
   *   What is asked: READ, SCHEMA, NARROW, WRITE, LIST or LOOKUP.
   * @param bool $custom
   *   Whether the path names the operation, `:{operation}`, rather than
   *   the method implying it.
   */
  public function __construct(
    public readonly ResourceDefinition $definition,
    public readonly OperationDefinition $operation,
    public readonly array $parameters,
    public readonly string $action,
    public readonly bool $custom,
  ) {}

}
