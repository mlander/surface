<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Generators\Resource;

use Drupal\Component\Serialization\Yaml;
use DrupalCodeGenerator\Asset\Asset;
use DrupalCodeGenerator\Asset\File;
use DrupalCodeGenerator\Asset\Resolver\ResolverInterface;
use DrupalCodeGenerator\InputOutput\IO;

/**
 * Adds one top-level entry to a YAML file that is there, keeping the rest.
 *
 * For a module's routing and permissions files: what the file holds is
 * read and kept as it is, byte for byte; the generated entry is added at
 * its end, after a blank line, unless the file already has an entry of
 * that name, which is then kept and the generated one left out. Either
 * way a note says which. A file that is not there is written as the
 * generated entry alone, which no resolver sees.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 */
final class YamlEntryResolver implements ResolverInterface {

  /**
   * Constructs a YamlEntryResolver.
   *
   * @param string $key
   *   The entry's top-level key: a route's name, a permission.
   * @param string $what
   *   What the entry is, for the note: `route`, `permission`.
   * @param \DrupalCodeGenerator\InputOutput\IO|null $io
   *   Where the note is written; NULL for none.
   */
  public function __construct(
    protected readonly string $key,
    protected readonly string $what,
    protected readonly ?IO $io = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(Asset $asset, string $path): ?Asset {
    if (!$asset instanceof File) {
      throw new \InvalidArgumentException('Only a file holds YAML entries.');
    }
    $existing = (string) file_get_contents($path);
    $merged = static::merge($existing, $this->key, $asset->getContent());
    if ($merged === NULL) {
      $this->io?->note(sprintf('%s already declares the %s %s: kept as it is, and the generated one left out.', $asset->getPath(), $this->what, $this->key));
      return NULL;
    }
    $this->io?->note(sprintf('%s: the %s %s added at its end; every other entry kept as it was.', $asset->getPath(), $this->what, $this->key));
    return (clone $asset)->content($merged);
  }

  /**
   * Adds an entry to a file's text, unless it has one of that name.
   *
   * @param string $existing
   *   The file as it is.
   * @param string $key
   *   The entry's top-level key.
   * @param string $entry
   *   The entry's lines.
   *
   * @return string|null
   *   The file with the entry at its end; NULL when it has the key.
   */
  public static function merge(string $existing, string $key, string $entry): ?string {
    $decoded = trim($existing) === '' ? [] : Yaml::decode($existing);
    if (is_array($decoded) && array_key_exists($key, $decoded)) {
      return NULL;
    }
    $kept = rtrim($existing, "\n");
    return ($kept === '' ? '' : $kept . "\n\n") . $entry;
  }

}
