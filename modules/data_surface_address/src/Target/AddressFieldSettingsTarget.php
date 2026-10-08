<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Target;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\Target\SchemaViolations;
use Drupal\data_surface_address\AddressSettingsShape;

/**
 * An address field's settings live on its field config entity.
 *
 * Loads by the identity its context knows — entity type, bundle and field
 * name, which the field instance surface hands its settings child as the
 * one door through the wall between them — and translates through
 * AddressSettingsShape, so the surface is the input shape and the field
 * goes on storing exactly what it always stored. Settings the shape does
 * not produce, a key some other code wrote, survive.
 *
 * Committed after its parent, so a field being added exists by the time
 * its settings are written.
 */
final class AddressFieldSettingsTarget implements SurfaceTargetInterface {

  /**
   * The config schema type the address field's settings are stored as.
   */
  protected const SCHEMA = 'field.field_settings.address';

  /**
   * Constructs an AddressFieldSettingsTarget.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds the rehearsed
   *   settings to the address field type's settings schema.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceContext $context): array {
    $field = $this->field($context);
    return $field === NULL ? [] : (new AddressSettingsShape())->fromStorage($field->getSettings());
  }

  /**
   * {@inheritdoc}
   *
   * The settings in the shape the field stores them, over what the field
   * stores today, held to the address field type's settings schema. A
   * field being added does not exist yet when this runs, so it rehearses
   * against nothing stored and the field's own defaults fill the rest
   * when it is committed.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    $field = $this->field($context);
    if ($field === NULL && !$context->creates) {
      throw $this->noField($context);
    }
    $settings = (new AddressSettingsShape())->toStorage($values) + ($field?->getSettings() ?? []);
    $keys = ['available_countries', 'langcode_override', 'field_overrides'];
    SchemaViolations::check($this->typedConfig, self::SCHEMA, $settings, array_combine($keys, $keys));
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $field = $this->field($context) ?? throw $this->noField($context);
    $field->setSettings($prepared + $field->getSettings())->save();
  }

  /**
   * Says that the settings have no field to belong to.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context, whose identity named none.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  protected function noField(SurfaceContext $context): \LogicException {
    return new \LogicException(sprintf(
      'The address field settings have no field to be written to: the context knows %s.',
      json_encode($context->known),
    ));
  }

  /**
   * Loads the field the context's identity names.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the context does not name one that exists.
   */
  protected function field(SurfaceContext $context): ?FieldConfigInterface {
    $known = $context->known;
    if (!isset($known['entity_type_id'], $known['bundle'], $known['field_name'])) {
      return NULL;
    }
    $field = $this->entityTypeManager->getStorage('field_config')
      ->load($known['entity_type_id'] . '.' . $known['bundle'] . '.' . $known['field_name']);
    return $field instanceof FieldConfigInterface ? $field : NULL;
  }

}
