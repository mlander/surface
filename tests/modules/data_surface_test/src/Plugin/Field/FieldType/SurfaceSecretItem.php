<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;

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
 * The flag is declared in the definition array, as the defaults beside
 * it are, because the attribute's definitions are array literals.
 * DefinitionMetadata::setSecret() is the spelling for a surface built in
 * code, and it writes the same key.
 *
 * @see \Drupal\data_surface\DefinitionMetadata::setSecret()
 */
#[FieldType(
  id: 'data_surface_secret',
  label: new TranslatableMarkup('Secret-bearing text (Data Surface test)'),
  description: new TranslatableMarkup('A short text field whose instance settings include a secret.'),
  category: 'plain_text',
  default_widget: 'string_textfield',
  default_formatter: 'string',
)]
#[DataSurfaceAware(definitions: [
  'endpoint' => new DataDefinition([
    'type' => 'string',
    'label' => new TranslatableMarkup('Endpoint'),
    'description' => new TranslatableMarkup('Where this field sends its values.'),
    'required' => FALSE,
    'default_value' => '',
  ]),
  'token' => new DataDefinition([
    'type' => 'string',
    'label' => new TranslatableMarkup('API key'),
    'description' => new TranslatableMarkup('The key this field authenticates with.'),
    'required' => FALSE,
    'default_value' => '',
    'secret' => TRUE,
  ]),
])]
class SurfaceSecretItem extends StringItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldSurface(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface {
    // The field item is bound to one field config entity, so it is its
    // own subject and a caller naming another has the wrong item.
    $this->surfaceSelfSubject($subject);
    return $this->surfaceFactory()->buildFromClass(static::class, NULL, 'field_type:data_surface_secret');
  }

}
