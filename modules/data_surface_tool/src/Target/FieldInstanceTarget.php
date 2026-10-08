<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Target;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * A field instance's values live on its field config entity.
 *
 * Loads by the identity the context knows, so the context never carries
 * an entity. A settings variant with a target of its own has its values
 * routed there and they never arrive here; a variant without one is
 * stored here, under 'settings', as given.
 */
final class FieldInstanceTarget implements SurfaceTargetInterface {

  /**
   * Constructs a FieldInstanceTarget.
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
    $field = $this->field($context->known);
    return $field === NULL ? [] : [
      'entity_type_id' => $field->getTargetEntityTypeId(),
      'bundle' => $field->getTargetBundle(),
      'field_type' => $field->getType(),
      'field_name' => $field->getName(),
      'label' => $field->getLabel(),
      'description' => $field->getDescription(),
      'required' => $field->isRequired(),
      'settings' => $field->getSettings(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $values): void {
    $identity = $context->known + $values;
    $field = $this->field($identity);
    if ($field === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a field that does not exist.', $context->operation));
      }
      $field = FieldConfig::create([
        'entity_type' => $identity['entity_type_id'],
        'bundle' => $identity['bundle'],
        'field_name' => $identity['field_name'],
      ]);
    }
    $field->setLabel((string) $values['label'])
      ->setDescription((string) ($values['description'] ?? ''))
      ->setRequired((bool) ($values['required'] ?? FALSE));
    if (array_key_exists('settings', $values) && is_array($values['settings'])) {
      $field->setSettings($values['settings'] + $field->getSettings());
    }
    $field->save();
  }

  /**
   * Loads the field some identity names.
   *
   * @param array $identity
   *   The identity: entity_type_id, bundle and field_name.
   *
   * @return \Drupal\field\Entity\FieldConfig|null
   *   The field, or NULL when the identity names none that exists.
   */
  protected function field(array $identity): ?FieldConfig {
    if (!isset($identity['entity_type_id'], $identity['bundle'], $identity['field_name'])) {
      return NULL;
    }
    $field = $this->entityTypeManager->getStorage('field_config')
      ->load($identity['entity_type_id'] . '.' . $identity['bundle'] . '.' . $identity['field_name']);
    return $field instanceof FieldConfig ? $field : NULL;
  }

}
