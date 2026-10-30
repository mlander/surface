<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators\Resource;

use Drupal\surface\Storage\ConfigSchemaGenerator;
use DrupalCodeGenerator\Asset\Asset;
use DrupalCodeGenerator\Asset\File;
use DrupalCodeGenerator\Asset\Resolver\ResolverInterface;
use DrupalCodeGenerator\InputOutput\IO;

/**
 * Lays generated entries into a config schema file that is there.
 *
 * As `drush surface:config-schema` lays them
 * (ConfigSchemaGenerator::merge()): an entry of the same name is replaced
 * under its comments, a new one added at the end, every other kept.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 */
final class SchemaEntryResolver implements ResolverInterface {

  /**
   * Constructs a SchemaEntryResolver.
   *
   * @param array<string, array{module: string, name: string, resource: string, schema: array}> $entries
   *   The entries, by name.
   * @param \DrupalCodeGenerator\InputOutput\IO|null $io
   *   Where the note is written; NULL for none.
   */
  public function __construct(
    protected readonly array $entries,
    protected readonly ?IO $io = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(Asset $asset, string $path): Asset {
    if (!$asset instanceof File) {
      throw new \InvalidArgumentException('Only a file holds schema entries.');
    }
    $this->io?->note(sprintf('%s: %s written as drush surface:config-schema writes it; every other entry kept as it was.', $asset->getPath(), implode(', ', array_keys($this->entries))));
    return (clone $asset)->content(ConfigSchemaGenerator::merge((string) file_get_contents($path), $this->entries));
  }

}
