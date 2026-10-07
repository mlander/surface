<?php

declare(strict_types=1);

namespace Drupal\field\Target;

use Drupal\field\Entity\FieldConfig;
use Drupal\surface_sketch\Surface\SurfaceContext;
use Drupal\surface_sketch\Surface\SurfaceTargetInterface;

/**
 * A field instance's values live on its field config entity.
 *
 * Loads by the identity the context knows. The storage subsurface has
 * its own target, so its values never arrive here; the settings
 * subsurface has none, so they arrive under 'settings'.
 */
final class FieldInstanceTarget implements SurfaceTargetInterface {

  public function load(SurfaceContext $context): array {
    $field = $this->field($context);
    return $field === NULL ? [] : [
      'label' => $field->getLabel(),
      'description' => $field->getDescription(),
      'required' => $field->isRequired(),
      'settings' => $field->getSettings(),
    ];
  }

  public function commit(SurfaceContext $context, array $values): void {
    $field = $this->field($context) ?? FieldConfig::create([
      'entity_type' => $values['entity_type_id'],
      'bundle' => $values['bundle'],
      'field_name' => $values['field_name'],
    ]);
    $field->setLabel($values['label'])
      ->setDescription($values['description'] ?? '')
      ->setRequired($values['required'] ?? FALSE)
      ->setSettings($values['settings'] ?? [])
      ->save();
  }

  private function field(SurfaceContext $context): ?FieldConfig {
    $known = $context->known;
    if (!isset($known['entity_type_id'], $known['bundle'], $known['field_name'])) {
      return NULL;
    }
    return FieldConfig::loadByName($known['entity_type_id'], $known['bundle'], $known['field_name']);
  }

}
