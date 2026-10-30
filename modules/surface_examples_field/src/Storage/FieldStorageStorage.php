<?php

declare(strict_types=1);

namespace Drupal\surface_examples_field\Storage;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\surface\Storage\SchemaViolations;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * A field storage's values live on its field storage config entity.
 *
 * Loads by the identity its context knows: the entity type and the field
 * name, its update's parameters, or handed to it by the field it belongs
 * to. Committed before that
 * field, because a field cannot be created on a storage that does not
 * exist yet. A storage whose values did not move is not saved. Its
 * settings, whichever variant described them, are stored here under
 * `settings`, as the storage's own.
 */
final class FieldStorageStorage implements ResourceStorageInterface {

  use StringTranslationTrait;

  /**
   * Constructs a FieldStorageStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds a rehearsed storage
   *   to its config schema.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, autowired.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $storage = $this->storage($context->known);
    return $storage === NULL ? [] : [
      'entity_type' => $storage->getTargetEntityTypeId(),
      'field_name' => $storage->getName(),
      'field_type' => $storage->getType(),
      'cardinality' => $storage->getCardinality(),
      'translatable' => $storage->isTranslatable(),
      'settings' => $storage->getSettings(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The storage it would save, unsaved — a copy of the one the identity
   * names, or a new one built from the identity the field hands it — as
   * its exported array, held to the storage's config schema. Two checks
   * storage makes on save are made here instead: a field type other than
   * the field's, which core refuses with an exception, and, once the
   * field holds data, a settings change that alters its columns, which
   * the SQL storage refuses with one.
   *
   * @throws \InvalidArgumentException
   *   When the context does not say which storage: a field storage is
   *   created by the field that uses it, which hands it its identity.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $original = $this->storage($context->known);
    $this->assertSameIdentity($context, $original, $values);
    $storage = $original === NULL ? $this->create($context, NULL, $values['field_type'] ?? NULL) : clone $original;
    $this->apply($storage, $values);
    $record = $storage->toArray();
    SchemaViolations::check($this->typedConfig, $storage->getConfigDependencyName(), $record, [
      'cardinality' => 'cardinality',
      'translatable' => 'translatable',
      'settings' => 'settings',
    ]);
    if ($original !== NULL && $this->changesColumns($original, $record)) {
      $message = $this->t('The field %field has data, so its storage settings cannot change in a way that changes its database columns.', [
        '%field' => $original->getName(),
      ]);
      throw new StorageViolationsException(new ViolationSet([new Violation('settings', '', $message, 'has_data')]));
    }
    return $record;
  }

  /**
   * {@inheritdoc}
   *
   * A storage whose values did not move is not saved.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $storage = $this->storage($context->known);
    if ($storage === NULL) {
      $storage = $this->create($context, $prepared['uuid'] ?? NULL, $prepared['type'] ?? NULL);
    }
    elseif ((int) ($prepared['cardinality'] ?? $storage->getCardinality()) === $storage->getCardinality()
      && (bool) ($prepared['translatable'] ?? $storage->isTranslatable()) === $storage->isTranslatable()
      && ($prepared['settings'] ?? $storage->getSettings()) == $storage->getSettings()) {
      return;
    }
    $this->apply($storage, $prepared);
    $storage->save();
  }

  /**
   * Applies the values a caller may change to a storage.
   *
   * @param \Drupal\field\FieldStorageConfigInterface $storage
   *   The storage, a copy or one about to be saved.
   * @param array $values
   *   Accepted values, or the record prepare() returned: both carry
   *   cardinality, translatable and settings under those names.
   */
  protected function apply(FieldStorageConfigInterface $storage, array $values): void {
    if (array_key_exists('cardinality', $values)) {
      $storage->setCardinality((int) $values['cardinality']);
    }
    if (array_key_exists('translatable', $values)) {
      $storage->setTranslatable((bool) $values['translatable']);
    }
    if (is_array($values['settings'] ?? NULL)) {
      $storage->setSettings($values['settings'] + $storage->getSettings());
    }
  }

  /**
   * Refuses an identity other than the one the storage is for.
   *
   * The field hands its storage its own identity when it is prepared; a
   * caller adding a field names the type on the field and, to describe
   * the storage's settings, may name it on the storage as well. A storage
   * named for another entity type, field name or type would be one its
   * field cannot be built on.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context, which may know the identity.
   * @param \Drupal\field\FieldStorageConfigInterface|null $original
   *   The stored storage, if there is one.
   * @param array $values
   *   The accepted values, which may name the identity.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   When the two differ.
   */
  protected function assertSameIdentity(OperationContext $context, ?FieldStorageConfigInterface $original, array $values): void {
    $expected = [
      'entity_type' => $original?->getTargetEntityTypeId() ?? $context->known['entity_type'] ?? NULL,
      'field_name' => $original?->getName() ?? $context->known['field_name'] ?? NULL,
      'field_type' => $original?->getType() ?? $context->known['field_type'] ?? NULL,
    ];
    $violations = [];
    foreach ($expected as $key => $value) {
      $sent = $values[$key] ?? NULL;
      if (!is_string($sent) || $sent === '' || $value === NULL || $sent === $value) {
        continue;
      }
      $arguments = ['%expected' => $value, '%sent' => $sent];
      $message = $key === 'field_type'
        ? $this->t('The storage is for a %expected field, not %sent: the storage and its field have one type.', $arguments)
        : $this->t('The storage is named %expected, not %sent: a storage is named as its field is.', $arguments);
      $violations[] = new Violation($key, '', $message, $key === 'field_type' ? Violation::TYPE_MISMATCH : Violation::LOCKED);
    }
    if ($violations !== []) {
      throw new StorageViolationsException(new ViolationSet($violations));
    }
  }

  /**
   * Says whether a rehearsed record changes a storage's columns with data.
   *
   * What the SQL storage refuses on save for a field that holds values:
   * the schema of its columns may not move. Indexes may, so only the
   * columns are compared, on a fresh definition built from the record,
   * whose schema is computed from its settings rather than cached.
   *
   * @param \Drupal\field\FieldStorageConfigInterface $original
   *   The stored storage.
   * @param array $record
   *   What prepare() would hand commit().
   *
   * @return bool
   *   TRUE when the columns change and the field has data.
   */
  protected function changesColumns(FieldStorageConfigInterface $original, array $record): bool {
    if (($record['settings'] ?? []) == $original->getSettings()) {
      return FALSE;
    }
    $rehearsed = $this->entityTypeManager->getStorage('field_storage_config')->create($record);
    return $rehearsed->getSchema()['columns'] != $original->getSchema()['columns']
      && $original instanceof FieldStorageConfig
      && $original->hasData();
  }

  /**
   * Builds a new, unsaved storage from the identity its field hands it.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context, which creates and knows the entity type, the field name
   *   and the field type.
   * @param string|null $uuid
   *   The uuid a rehearsal handed out, so what was previewed is what is
   *   stored.
   * @param mixed $type
   *   The field type the values name, when the context does not know one.
   *
   * @return \Drupal\field\FieldStorageConfigInterface
   *   The unsaved storage.
   *
   * @throws \InvalidArgumentException
   *   When the context does not create, or does not say which storage.
   */
  protected function create(OperationContext $context, ?string $uuid = NULL, mixed $type = NULL): FieldStorageConfigInterface {
    $known = $context->known + (is_string($type) && $type !== '' ? ['field_type' => $type] : []);
    if (!$context->creates || !isset($known['entity_type'], $known['field_name'], $known['field_type'])) {
      throw new \InvalidArgumentException(sprintf(
        'A field storage is written for a field, which says which one: the "%s" context knows no %s.',
        $context->id,
        $context->creates ? 'entity type, field name and field type' : 'existing storage',
      ));
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')->create(array_filter([
      'entity_type' => $known['entity_type'],
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
   *   The identity: entity_type and field_name.
   *
   * @return \Drupal\field\FieldStorageConfigInterface|null
   *   The storage, or NULL when the identity names none that exists.
   */
  protected function storage(array $known): ?FieldStorageConfigInterface {
    if (!isset($known['entity_type'], $known['field_name'])) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load($known['entity_type'] . '.' . $known['field_name']);
    return $storage instanceof FieldStorageConfigInterface ? $storage : NULL;
  }

}
