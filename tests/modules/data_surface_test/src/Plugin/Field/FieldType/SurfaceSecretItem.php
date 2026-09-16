<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceDeclarationInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
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
 * The flag is written onto the definition with
 * DefinitionMetadata::setSecret(), beside the defaults the builder
 * writes, because both are metadata core's definitions have no methods
 * for yet.
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
class SurfaceSecretItem extends StringItem implements FieldSurfaceProviderInterface, DataSurfaceDeclarationInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * {@inheritdoc}
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $builder->setDefinition('endpoint', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Endpoint'))
      ->setDescription(new TranslatableMarkup('Where this field sends its values.'))
      ->setRequired(FALSE));
    $builder->setDefault('endpoint', '');

    $token = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('API key'))
      ->setDescription(new TranslatableMarkup('The key this field authenticates with.'))
      ->setRequired(FALSE);
    DefinitionMetadata::setSecret($token);
    $builder->setDefinition('token', $token);
    $builder->setDefault('token', '');
  }

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
    return $this->declaredSurface('field_type:data_surface_secret');
  }

}
