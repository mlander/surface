<?php

declare(strict_types=1);

namespace Drupal\surface_tool;

/**
 * Every UI field type, each its storage settings' schema.
 *
 * Lists FieldStorageResource's settings variants, named by its
 * `attachVariants('settings', by: 'field_type', from: ...)`: the keys of
 * `field.storage_settings.<field type>`, with the field type's own default
 * storage settings, so a string's `max_length` can be set when a field is
 * added and changed when its storage is edited.
 */
final class FieldStorageSettingsSchemaVariants extends FieldSettingsSchemaVariants {

  /**
   * {@inheritdoc}
   */
  protected const SCHEMA = 'field.storage_settings.';

  /**
   * {@inheritdoc}
   */
  protected const DEFAULTS = 'defaultStorageSettings';

}
