<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 12's spotlight: the component and its props, in one object.
 *
 * Its config schema is written from the resource: the props are an open
 * map there, since which components a site has is the site's, and the
 * chosen component's variant holds them to its props before anything is
 * written.
 */
final class SpotlightStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.spotlight';

}
