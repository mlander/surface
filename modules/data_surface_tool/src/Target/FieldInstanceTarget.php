<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Target;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\Target\SchemaViolations;
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
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds a rehearsed field
   *   to its config schema.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository
   *   The entity display repository, autowired, for placing a new field.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly EntityDisplayRepositoryInterface $displayRepository,
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
      // The field's own settings, not getSettings(), which adds the
      // storage's: those are the storage's to describe and to store.
      'settings' => $field->get('settings'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The field it would save, unsaved — a copy of the one the identity
   * names, or a new one — as its exported array, held to the field's
   * config schema. A field being added may be added on a storage that is
   * itself still being rehearsed by the storage child, so a storage that
   * does not exist yet is stood in for by an unsaved one built from the
   * same identity, which is what the field is checked against.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    $identity = $context->known + $values;
    $field = $this->field($identity);
    if ($field === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a field that does not exist.', $context->operation));
      }
      $field = $this->create($identity);
    }
    else {
      // The stored field is read, never written: everything below
      // happens to a copy.
      $field = clone $field;
    }
    $field->setLabel((string) ($values['label'] ?? $field->getLabel()))
      ->setDescription((string) ($values['description'] ?? $field->getDescription()))
      ->setRequired((bool) ($values['required'] ?? $field->isRequired()));
    if (is_array($values['settings'] ?? NULL)) {
      $field->setSettings($values['settings'] + $field->get('settings'));
    }
    $record = $field->toArray();
    // The settings are this target's to answer for only when they arrived
    // here: a variant with a target of its own is checked by that target.
    $paths = ['field_name', 'label', 'description', 'required'];
    if (array_key_exists('settings', $values)) {
      $paths[] = 'settings';
    }
    SchemaViolations::check($this->typedConfig, $field->getConfigDependencyName(), $record, array_combine($paths, $paths));
    return $record;
  }

  /**
   * {@inheritdoc}
   *
   * A field just created is placed on the bundle's default form and view
   * displays, with the field type's default widget and formatter, as
   * Field UI does when it adds one.
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $identity = $context->known + [
      'entity_type_id' => $prepared['entity_type'] ?? NULL,
      'bundle' => $prepared['bundle'] ?? NULL,
      'field_name' => $prepared['field_name'] ?? NULL,
    ];
    $field = $this->field($identity);
    $created = $field === NULL;
    if ($created) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a field that does not exist.', $context->operation));
      }
      $field = $this->entityTypeManager->getStorage('field_config')->create(array_filter([
        'entity_type' => $identity['entity_type_id'],
        'bundle' => $identity['bundle'],
        'field_name' => $identity['field_name'],
        'uuid' => $prepared['uuid'] ?? NULL,
      ]));
    }
    $field->setLabel((string) ($prepared['label'] ?? $field->getLabel()))
      ->setDescription((string) ($prepared['description'] ?? ''))
      ->setRequired((bool) ($prepared['required'] ?? FALSE));
    if (is_array($prepared['settings'] ?? NULL)) {
      $field->setSettings($prepared['settings'] + $field->get('settings'));
    }
    $field->save();
    if ($created) {
      $this->displayRepository->getFormDisplay($field->getTargetEntityTypeId(), $field->getTargetBundle())
        ->setComponent($field->getName(), [])
        ->save();
      $this->displayRepository->getViewDisplay($field->getTargetEntityTypeId(), $field->getTargetBundle())
        ->setComponent($field->getName(), [])
        ->save();
    }
  }

  /**
   * Builds a new, unsaved field from an identity.
   *
   * @param array $identity
   *   The identity: entity_type_id, bundle, field_name and field_type.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The unsaved field, on its stored storage or on an unsaved stand-in
   *   for a storage being added beside it.
   */
  protected function create(array $identity): FieldConfig {
    $storage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load($identity['entity_type_id'] . '.' . $identity['field_name'])
      ?? $this->entityTypeManager->getStorage('field_storage_config')->create([
        'entity_type' => $identity['entity_type_id'],
        'field_name' => $identity['field_name'],
        'type' => $identity['field_type'],
      ]);
    $field = $this->entityTypeManager->getStorage('field_config')->create([
      'field_storage' => $storage,
      'bundle' => $identity['bundle'],
    ]);
    return $field;
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
