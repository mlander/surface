<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldType;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceDeclarationInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;

/**
 * A field type whose settings surface can refuse an account.
 *
 * The one thing no other fixture can show: a field type that allows
 * everything core's field permissions allow and still says no to
 * configuring its own settings. Everything else about it is ordinary —
 * one string setting, the plain settings target — so a test that sees a
 * caller refused has seen the surface's answer refuse it and nothing
 * else.
 *
 * The refusal is switched by state rather than by a permission, because
 * a permission is exactly what the host gate already asks and the point
 * of the fixture is an answer the host gate does not have.
 */
#[FieldType(
  id: 'data_surface_gated',
  label: new TranslatableMarkup('Gated text (Data Surface test)'),
  description: new TranslatableMarkup('A short text field whose instance settings the field type itself may refuse.'),
  category: 'plain_text',
  default_widget: 'string_textfield',
  default_formatter: 'string',
)]
class SurfaceGatedItem extends StringItem implements FieldSurfaceProviderInterface, DataSurfaceDeclarationInterface {

  use DataSurfaceFieldTypeTrait {
    surfaceAccess as protected fieldConfigAccess;
  }

  /**
   * The state key that makes this field type refuse its settings.
   */
  public const REFUSE_STATE_KEY = 'data_surface_test.field_settings_refused';

  /**
   * {@inheritdoc}
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $builder->setDefinition('note', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Note'))
      ->setDescription(new TranslatableMarkup('A note stored with this field instance.')));
    // NULL is a declared default, and declaring one is not the same as
    // declaring none: the key starts empty rather than absent.
    $builder->setDefault('note', NULL);
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
    return $this->declaredSurface('field_type:data_surface_gated');
  }

  /**
   * {@inheritdoc}
   *
   * A refusal of its own on top of the trait's default, which is the
   * field config entity's own answer. The two are ANDed by being asked
   * in this order: whatever the entity said, this says no while the flag
   * is set, and defers to the entity when it is not.
   */
  public function surfaceAccess(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    if (\Drupal::state()->get(self::REFUSE_STATE_KEY, FALSE)) {
      return AccessResult::forbidden('The gated test field type does not allow its settings to be configured.');
    }
    return $this->fieldConfigAccess($operation, $subject, $account);
  }

}
