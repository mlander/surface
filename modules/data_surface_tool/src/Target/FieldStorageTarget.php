<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Target;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
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
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
   * @throws \InvalidArgumentException
   *   When the context does not say which storage: a field storage is
   *   created by the field that uses it, which hands it its identity.
   */
  public function commit(SurfaceContext $context, array $values): void {
    $known = $context->known;
    $storage = $this->storage($known);
    if ($storage === NULL) {
      if (!$context->creates || !isset($known['entity_type_id'], $known['field_name'], $known['field_type'])) {
        throw new \InvalidArgumentException(sprintf(
          'A field storage is written for a field, which says which one: the "%s" context knows no %s.',
          $context->operation,
          $context->creates ? 'entity type, field name and field type' : 'existing storage',
        ));
      }
      $storage = $this->entityTypeManager->getStorage('field_storage_config')->create([
        'entity_type' => $known['entity_type_id'],
        'field_name' => $known['field_name'],
        'type' => $known['field_type'],
      ]);
    }
    elseif (($values['cardinality'] ?? $storage->getCardinality()) === $storage->getCardinality()
      && (bool) ($values['translatable'] ?? $storage->isTranslatable()) === $storage->isTranslatable()) {
      return;
    }
    if (array_key_exists('cardinality', $values)) {
      $storage->setCardinality((int) $values['cardinality']);
    }
    if (array_key_exists('translatable', $values)) {
      $storage->setTranslatable((bool) $values['translatable']);
    }
    $storage->save();
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
