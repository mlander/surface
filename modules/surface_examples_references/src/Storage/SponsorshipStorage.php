<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 16's sponsorship, in its own config object.
 */
final class SponsorshipStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples_references.sponsorship';

}
