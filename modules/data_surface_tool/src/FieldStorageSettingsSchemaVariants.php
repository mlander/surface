<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\data_surface_tool\Surface\FieldStorageSurface;

/**
 * A field type's storage settings, read from its config schema.
 *
 * Fills FieldStorageSurface's settings slot for every field type a site
 * offers in its UI that no #[SurfaceVariant] fills: the keys of
 * `field.storage_settings.<field type>`, with the field type's own
 * default storage settings, so a string's `max_length` can be set when
 * a field is added and changed when its storage is edited.
 */
final class FieldStorageSettingsSchemaVariants extends FieldSettingsSchemaVariants {

  /**
   * {@inheritdoc}
   */
  protected const SURFACE = FieldStorageSurface::class;

  /**
   * {@inheritdoc}
   */
  protected const SCHEMA = 'field.storage_settings.';

  /**
   * {@inheritdoc}
   */
  protected const DEFAULTS = 'defaultStorageSettings';

}
