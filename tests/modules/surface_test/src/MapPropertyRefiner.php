<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\PortableKeywords;
use PortableSchema\Builder\Schema;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * A refiner that tightens one property inside a map.
 *
 * The case a shallow clone gets wrong: core's MapDataDefinition has no
 * __clone of its own, so a cloned map shares its property definitions
 * with the original and this refiner would tighten the sealed data surface
 * rather than the copy it was handed.
 */
final class MapPropertyRefiner implements SurfaceRefinerInterface {

  /**
   * The property this refiner tightens.
   */
  public const PROPERTY = 'note';

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values): array {
    if (DefinitionSchema::propertyNode($key, self::PROPERTY) === NULL) {
      return [];
    }
    return [
      Schema::any()->with('properties', [
        self::PROPERTY => Schema::any()->with(PortableKeywords::CHECK, ['drupal:Length' => ['max' => 3]]),
      ]),
    ];
  }

}
