<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Generators\Resource;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;

/**
 * A source of the resource generator, built.
 *
 * @see \Drupal\data_surface\Drush\Generators\Resource\ResourceSources
 */
final class BuiltSource {

  /**
   * Constructs a BuiltSource.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The keys, sealed as declared.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $owner
   *   The structure it was registered as: the resource's id and module.
   * @param string $source
   *   What it was read from, in words.
   * @param array<string, string> $notes
   *   What the schema reader noted, by JSON pointer.
   * @param array<string, array{constraints?: array<string, mixed>, type?: string, default?: mixed}> $left
   *   What a config schema states that its document does not, by dotted
   *   key path.
   */
  public function __construct(
    public readonly DataSurfaceInterface $built,
    public readonly ResourceDefinition $owner,
    public readonly string $source,
    public readonly array $notes = [],
    public readonly array $left = [],
  ) {}

}
