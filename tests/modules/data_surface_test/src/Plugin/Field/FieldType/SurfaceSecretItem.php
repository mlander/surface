<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Surface\SecretFieldSettingsSurface;

/**
 * A field type with one secret instance setting and one ordinary one.
 *
 * The smallest fixture that can show what the secret flag costs: a key
 * beside the secret, so "the secret is enciphered" and "nothing else is"
 * are two assertions about one saved field rather than one assertion
 * about two fixtures. The ordinary key is also what a partial update
 * moves while the secret is meant to stand still, which is the case that
 * turns the keep rule from a nicety into a requirement.
 *
 * Everything about it is otherwise stock: the plain settings target,
 * with no shape of its own, so the encryption the test sees is the
 * generic wiring in FieldSettingsTarget and not something this fixture
 * arranged.
 *
 * The settings are SecretFieldSettingsSurface, named with
 * #[UsesSurface], whose token carries the secret flag.
 *
 * @see \Drupal\data_surface_test\Surface\SecretFieldSettingsSurface
 */
#[FieldType(
  id: 'data_surface_secret',
  label: new TranslatableMarkup('Secret-bearing text (Data Surface test)'),
  description: new TranslatableMarkup('A short text field whose instance settings include a secret.'),
  category: 'plain_text',
  default_widget: 'string_textfield',
  default_formatter: 'string',
)]
#[UsesSurface(SecretFieldSettingsSurface::class)]
class SurfaceSecretItem extends StringItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

}
