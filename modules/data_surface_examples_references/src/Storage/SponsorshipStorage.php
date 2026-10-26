<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_references\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 16's sponsorship, in its own config object.
 */
final class SponsorshipStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples_references.sponsorship';

}
