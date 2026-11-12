<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\Reference\References;

/**
 * Says what a document publishes of a check's options, plugin by plugin.
 *
 * The rule: a built node holds each check's options as the definition
 * holds them, the ones the check provider runs; a document says only what
 * a client needs of them, one row per plugin:
 * - SurfaceValidReference: what the key refers to, `{entityTypeId,
 *   bundles?}`, the plugin's own option names, the bundles only where it
 *   names any; the one fact a client needs of it, and what a reader reads
 *   the key back from. Options that name no entity type publish `{}`.
 * - JsonSchema: the schema a value is held to, `{schema}`, `{}` for an
 *   empty one, so a reader reads the same constraint back. Options that
 *   are no array publish `{}`.
 * - Every other plugin: `{}`. The document says which plugin checks, not
 *   how it is configured.
 * A new plugin whose options a client needs is one row here.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-once-into-a-built-node,
 * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
 *
 * @internal
 */
final class CheckOptions {

  /**
   * Says a check's options as a document publishes them.
   *
   * @param string $id
   *   The constraint's plugin id, without `drupal:`.
   * @param mixed $options
   *   Its options as the definition holds them: an array, NULL or a
   *   scalar.
   *
   * @return array|\stdClass
   *   The published options; an empty \stdClass, which a document writes
   *   as `{}`, where the row publishes none.
   */
  public static function published(string $id, mixed $options): array|\stdClass {
    if ($id === 'JsonSchema' && is_array($options)) {
      $schema = is_array($options['schema'] ?? NULL) ? $options['schema'] : [];
      return ['schema' => $schema === [] ? new \stdClass() : $schema];
    }
    $target = $id === References::CONSTRAINT && is_array($options) ? References::targetOf($options) : NULL;
    if ($target !== NULL) {
      return ['entityTypeId' => $target['entityType']] + ($target['bundles'] === [] ? [] : ['bundles' => $target['bundles']]);
    }
    return new \stdClass();
  }

}
