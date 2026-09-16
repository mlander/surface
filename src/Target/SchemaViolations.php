<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\data_surface\Pipeline\SurfaceViolation;
use Drupal\data_surface\Pipeline\TargetViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;

/**
 * Validates storage shaped data and reports it back in surface terms.
 *
 * Both config targets face the same small problem twice: the config
 * schema validates a whole data array and names its violations by config
 * property path, while the pipeline files violations under surface keys.
 * The translation lives here once, so the config object target and the
 * config entity target cannot drift apart on how a schema complaint
 * reaches the caller.
 *
 * Only the paths the surface writes are reported by default, and that is
 * the important rule. A schema validates the whole object, so a config
 * object carrying a violation under a key the surface never declared —
 * written by another module, or left behind by an older schema — would
 * otherwise refuse a submission that has nothing to do with it, with a
 * message about a key the caller cannot even see. Such a violation is
 * real, and it is somebody's problem; it is not this write's problem.
 * Callers that want the whole picture, such as a health check or a
 * migration, ask for it with $mapped_only set to FALSE.
 *
 * A config name with no schema is not an error: plenty of config still
 * has none, and a target that insisted would be unusable there. Such
 * data passes with no violations.
 */
final class SchemaViolations {

  /**
   * Validates data against its config schema, or does nothing.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed configuration manager.
   * @param string $schema_name
   *   The config name whose schema describes the data, such as
   *   'system.site' or 'config_test.dynamic.example'.
   * @param array $data
   *   The data in storage shape: a config object's raw data, or a config
   *   entity's exported array.
   * @param array<string, string> $paths
   *   Config property path => surface key, for naming violations.
   * @param bool $mapped_only
   *   TRUE, the default, to report only violations under a mapped path.
   *   FALSE to report every violation the schema found, with the ones no
   *   surface key claims filed under their own property path.
   *
   * @throws \Drupal\data_surface\Pipeline\TargetViolationsException
   *   When the data breaks the schema's constraints.
   */
  public static function check(TypedConfigManagerInterface $typed_config, string $schema_name, array $data, array $paths, bool $mapped_only = TRUE): void {
    $violations = static::collect($typed_config, $schema_name, $data, $paths, $mapped_only);
    if (!$violations->isEmpty()) {
      throw new TargetViolationsException($violations);
    }
  }

  /**
   * Validates data against its config schema and returns the violations.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed configuration manager.
   * @param string $schema_name
   *   The config name whose schema describes the data.
   * @param array $data
   *   The data in storage shape.
   * @param array<string, string> $paths
   *   Config property path => surface key, for naming violations.
   * @param bool $mapped_only
   *   TRUE, the default, to report only violations under a mapped path.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, filed under the surface keys that own them; empty
   *   when the data is valid or when nothing describes it.
   */
  public static function collect(TypedConfigManagerInterface $typed_config, string $schema_name, array $data, array $paths, bool $mapped_only = TRUE): ViolationSet {
    if (!$typed_config->hasConfigSchema($schema_name)) {
      return new ViolationSet();
    }
    $violations = [];
    foreach ($typed_config->createFromNameAndData($schema_name, $data)->validate() as $violation) {
      $property_path = (string) $violation->getPropertyPath();
      $placed = static::place($property_path, $paths);
      if ($placed === NULL) {
        if ($mapped_only) {
          continue;
        }
        $placed = [$property_path, ''];
      }
      // The schema's message object travels on as an object, exactly as
      // the pipeline's own violations do, so a caller renders it once.
      $violations[] = new SurfaceViolation($placed[0], $placed[1], $violation->getMessage());
    }
    return new ViolationSet($violations);
  }

  /**
   * Places one config property path under the surface key that owns it.
   *
   * The longest mapped path wins, so a surface key holding a whole map
   * claims the violations inside that map and keeps the rest of the path
   * as the position within its own value.
   *
   * @param string $property_path
   *   The property path the schema reported, such as 'page.front'.
   * @param array<string, string> $paths
   *   Config property path => surface key.
   *
   * @return array{0: string, 1: string}|null
   *   The surface key and the remaining path within it, or NULL when no
   *   surface key claims the path.
   */
  protected static function place(string $property_path, array $paths): ?array {
    $best = NULL;
    foreach (array_keys($paths) as $mapped) {
      if ($property_path !== $mapped && !str_starts_with($property_path, $mapped . '.')) {
        continue;
      }
      if ($best === NULL || strlen($mapped) > strlen($best)) {
        $best = $mapped;
      }
    }
    if ($best === NULL) {
      return NULL;
    }
    return [$paths[$best], substr($property_path, strlen($best) + 1)];
  }

}
