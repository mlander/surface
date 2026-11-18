<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 14: where the webhook settings are kept.
 */
final class WebhookStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.webhook';

}
