<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Example 19's event notices, in a config object of their own.
 *
 * Apart from the event setup's, so the two are two things in one stage.
 * The object always exists, from config/install, or on a site that had
 * the module from surface_examples_post_update_install_event_setup().
 */
final class EventNoticesStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.event_notices';

}
