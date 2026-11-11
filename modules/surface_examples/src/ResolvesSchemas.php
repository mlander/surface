<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Schemas;
use PortableSchema\Builder\Schema;

/**
 * Resolves a schema of a document a panel holds as arrays.
 *
 * SchemaResolver reads Schema values: the arrays a panel holds are read
 * in, and the result written back in the array form.
 */
trait ResolvesSchemas {

  /**
   * Resolves a schema of the full document, read as the panel reads it.
   *
   * @param array $schema
   *   A schema in the document.
   * @param array $full
   *   The document.
   *
   * @return array
   *   The effective schema; empty for one that says nothing.
   */
  protected static function resolved(array $schema, array $full): array {
    $resolved = Schemas::arrayed(SchemaResolver::resolve(Schema::from($schema), Schema::from($full)));
    return is_array($resolved) ? $resolved : [];
  }

}
