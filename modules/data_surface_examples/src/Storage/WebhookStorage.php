<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 14: where the webhook settings are kept.
 */
final class WebhookStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.webhook';

}
