<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Target;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
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
   * Constructs an AddressFieldSettingsTarget.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
   */
  public function commit(SurfaceContext $context, array $values): void {
    $field = $this->field($context) ?? throw new \LogicException(sprintf(
      'The address field settings have no field to be written to: the context knows %s.',
      json_encode($context->known),
    ));
    $field->setSettings((new AddressSettingsShape())->toStorage($values) + $field->getSettings())->save();
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
