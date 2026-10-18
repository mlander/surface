<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the newsletter settings in their config object.
 */
final class NewsletterStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_first.newsletter';

}
