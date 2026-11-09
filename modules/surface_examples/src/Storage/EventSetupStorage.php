<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Example 19's event setup, in its own config object.
 *
 * The object always exists, from config/install, or on a site that had
 * the module from surface_examples_post_update_install_event_setup().
 */
final class EventSetupStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.event_setup';

}
