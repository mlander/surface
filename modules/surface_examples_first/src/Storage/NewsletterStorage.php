<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps the newsletter settings in their config object.
 */
final class NewsletterStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples_first.newsletter';

}
