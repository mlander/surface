<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Target;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\Target\SchemaViolations;
use Drupal\field\FieldStorageConfigInterface;

/**
 * A field storage's values live on its field storage config entity.
 *
 * Loads by the identity its context knows: the entity type and the field
 * name, handed to it by the field it belongs to. Committed before that
 * field, because a field cannot be created on a storage that does not
 * exist yet. A storage whose values did not move is not saved.
 */
final class FieldStorageTarget implements SurfaceTargetInterface {

  /**
   * Constructs a FieldStorageTarget.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds a rehearsed storage
   *   to its config schema.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceContext $context): array {
    $storage = $this->storage($context->known);
    return $storage === NULL ? [] : [
      'cardinality' => $storage->getCardinality(),
      'translatable' => $storage->isTranslatable(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The storage it would save, unsaved — a copy of the one the identity
   * names, or a new one built from the identity the field hands it — as
   * its exported array, held to the storage's config schema.
   *
   * @throws \InvalidArgumentException
   *   When the context does not say which storage: a field storage is
   *   created by the field that uses it, which hands it its identity.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    $storage = $this->storage($context->known);
    $storage = $storage === NULL ? $this->create($context) : clone $storage;
    if (array_key_exists('cardinality', $values)) {
      $storage->setCardinality((int) $values['cardinality']);
    }
    if (array_key_exists('translatable', $values)) {
      $storage->setTranslatable((bool) $values['translatable']);
    }
    $record = $storage->toArray();
    SchemaViolations::check($this->typedConfig, $storage->getConfigDependencyName(), $record, [
      'cardinality' => 'cardinality',
      'translatable' => 'translatable',
    ]);
    return $record;
  }

  /**
   * {@inheritdoc}
   *
   * A storage whose values did not move is not saved.
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $storage = $this->storage($context->known);
    if ($storage === NULL) {
      $storage = $this->create($context, $prepared['uuid'] ?? NULL);
    }
    elseif ((int) ($prepared['cardinality'] ?? $storage->getCardinality()) === $storage->getCardinality()
      && (bool) ($prepared['translatable'] ?? $storage->isTranslatable()) === $storage->isTranslatable()) {
      return;
    }
    if (array_key_exists('cardinality', $prepared)) {
      $storage->setCardinality((int) $prepared['cardinality']);
    }
    if (array_key_exists('translatable', $prepared)) {
      $storage->setTranslatable((bool) $prepared['translatable']);
    }
    $storage->save();
  }

  /**
   * Builds a new, unsaved storage from the identity its field hands it.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context, which creates and knows the entity type, the field name
   *   and the field type.
   * @param string|null $uuid
   *   The uuid a rehearsal handed out, so what was previewed is what is
   *   stored.
   *
   * @return \Drupal\field\FieldStorageConfigInterface
   *   The unsaved storage.
   *
   * @throws \InvalidArgumentException
   *   When the context does not create, or does not say which storage.
   */
  protected function create(SurfaceContext $context, ?string $uuid = NULL): FieldStorageConfigInterface {
    $known = $context->known;
    if (!$context->creates || !isset($known['entity_type_id'], $known['field_name'], $known['field_type'])) {
      throw new \InvalidArgumentException(sprintf(
        'A field storage is written for a field, which says which one: the "%s" context knows no %s.',
        $context->operation,
        $context->creates ? 'entity type, field name and field type' : 'existing storage',
      ));
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')->create(array_filter([
      'entity_type' => $known['entity_type_id'],
      'field_name' => $known['field_name'],
      'type' => $known['field_type'],
      'uuid' => $uuid,
    ]));
    return $storage;
  }

  /**
   * Loads the storage some identity names.
   *
   * @param array $known
   *   The identity: entity_type_id and field_name.
   *
   * @return \Drupal\field\FieldStorageConfigInterface|null
   *   The storage, or NULL when the identity names none that exists.
   */
  protected function storage(array $known): ?FieldStorageConfigInterface {
    if (!isset($known['entity_type_id'], $known['field_name'])) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load($known['entity_type_id'] . '.' . $known['field_name']);
    return $storage instanceof FieldStorageConfigInterface ? $storage : NULL;
  }

}
