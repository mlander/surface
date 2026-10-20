<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_test\Structure\GatedFieldSettingsStructure;

/**
 * A field type whose settings structure can refuse an account.
 *
 * The one thing no other fixture can show: a field type that allows
 * everything core's field permissions allow and still says no to
 * configuring its own settings. Everything else about it is ordinary
 * (one string setting, the plain settings storage), so a test that sees
 * a caller refused has seen the structure's answer refuse it and
 * nothing else.
 *
 * The settings are GatedFieldSettingsStructure, named with
 * #[UsesStructure], and the refusal is that structure's access class.
 * The field type host reads both, and so do the derived field tools,
 * because the same structure is the field instance resource's settings
 * variant for this field type. The class writes nothing else but
 * the static defaults its host protocol asks of it.
 *
 * @see \Drupal\data_surface_test\Structure\GatedFieldSettingsStructure
 * @see \Drupal\data_surface_test\Access\GatedFieldSettingsAccess
 */
#[FieldType(
  id: 'data_surface_gated',
  label: new TranslatableMarkup('Gated text (Data Surface test)'),
  description: new TranslatableMarkup('A short text field whose instance settings the field type itself may refuse.'),
  category: 'plain_text',
  default_widget: 'string_textfield',
  default_formatter: 'string',
)]
#[UsesStructure(GatedFieldSettingsStructure::class)]
class SurfaceGatedItem extends StringItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * The state key that makes this field type refuse its settings.
   */
  public const REFUSE_STATE_KEY = 'data_surface_test.field_settings_refused';

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

}
