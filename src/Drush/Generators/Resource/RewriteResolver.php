<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Generators\Resource;

use DrupalCodeGenerator\Asset\Asset;
use DrupalCodeGenerator\Asset\File;
use DrupalCodeGenerator\Asset\Resolver\ResolverInterface;
use DrupalCodeGenerator\InputOutput\IO;

/**
 * Writes a file that is there again, what it held already accounted for.
 *
 * For a config object's install file: the generator wrote its content
 * from the file as it is (a value the file gives a key is kept), as
 * `drush data_surface:config-schema --write` does, so it is written
 * without asking.
 */
final class RewriteResolver implements ResolverInterface {

  /**
   * Constructs a RewriteResolver.
   *
   * @param \DrupalCodeGenerator\InputOutput\IO|null $io
   *   Where the note is written; NULL for none.
   */
  public function __construct(
    protected readonly ?IO $io = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(Asset $asset, string $path): Asset {
    if (!$asset instanceof File) {
      throw new \InvalidArgumentException('Only a file is written again.');
    }
    $this->io?->note(sprintf('%s: written again; each value it gave a key it still declares is kept.', $asset->getPath()));
    return clone $asset;
  }

}
