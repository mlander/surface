<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool\Storage\FieldStorageStorage;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;

/**
 * What every bundle using a field shares: its storage.
 *
 * Attached by FieldInstanceResource at `storage`. Its one identity key is
 * the field type, which chooses its settings: which storage it is, is
 * otherwise the field's identity, which the field hands it: in its own
 * context when the field's operation knows the storage, and otherwise as
 * the field's accepted identity when it is committed. Nothing here
 * depends on anything entered, so there is no #[RefinesInput] method.
 *
 * The settings are a slot chosen by the field type, as the field's own
 * settings are: a field type's module marks a storage settings class
 * #[Variant(of: FieldStorageResource::class, key: 'settings')],
 * and every UI field type without one is filled from its config schema,
 * `field.storage_settings.<field type>`.
 *
 * @code
 *                     add       edit
 *   field_type        open      locked
 *   cardinality       open      open; may not shrink once there is data
 *   translatable      open      open
 *   settings          by type   fixed by the known type; the schema may
 *                               not change once there is data
 * @endcode
 */
#[Resource('field.storage',
  identity: ['field_type'],
  storage: FieldStorageStorage::class,
)]
final class FieldStorageResource implements ResourceInterface {

  /**
   * A new field storage.
   *
   * Knows nothing, so its permission cannot be named and it is refused
   * on its own: a storage is added by the field that uses it, whose
   * operation is the one asked.
   */
  #[Operation('add', label: 'New field storage', permission: 'administer %entity_type_id fields')]
  public static function add(): OperationContext {
    return new OperationContext('add', creates: TRUE);
  }

  /**
   * An existing field storage.
   *
   * Once the field holds data, cardinality may not shrink. That depends
   * on the storage, not on anything entered, so the operation says it,
   * with a constraint. Core's real rule counts the deltas in use;
   * simplified here to "no fewer than now", and to "unlimited stays
   * unlimited". A settings change the database could not take with data
   * in it is the storage class's to refuse, at prepare, because only the
   * field storage knows which settings reach the schema.
   */
  #[Operation('edit', label: 'Edit a field storage', permission: 'administer %entity_type_id fields')]
  public static function edit(FieldStorageConfigInterface $storage): OperationContext {
    // The field type is identity, so it is locked; the entity type and
    // the name are no keys here, known for the storage to load by.
    $context = new OperationContext('edit', known: [
      'entity_type_id' => $storage->getTargetEntityTypeId(),
      'field_name' => $storage->getName(),
      'field_type' => $storage->getType(),
    ]);
    // hasData() is the config entity's, not its interface's.
    if (!$storage instanceof FieldStorageConfig || !$storage->hasData()) {
      return $context;
    }
    $cardinality = $storage->getCardinality();
    return $cardinality === FieldStorageConfigInterface::CARDINALITY_UNLIMITED
      ? $context->withConstraint('cardinality', 'Choice', ['choices' => [FieldStorageConfigInterface::CARDINALITY_UNLIMITED]])
      : $context->withConstraint('cardinality', 'Range', ['min' => $cardinality]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    // Which storage this is, beyond what the field hands it.
    $inputs->add('field_type', 'string', t('Field type'))
      ->setDescription(t("The field type, which decides what the storage settings are. The same as the field's."));

    // The storage itself.
    $inputs->add('cardinality', 'integer', t('Allowed number of values'), default: 1)
      ->setDescription(t('How many values the field holds: a number of one or more, or -1 for unlimited.'))
      ->addConstraint('Range', ['min' => FieldStorageConfigInterface::CARDINALITY_UNLIMITED]);
    $inputs->add('translatable', 'boolean', t('Translatable'), default: TRUE);

    // Its part.
    $inputs->attachBy('settings', by: 'field_type')
      ->setLabel(t('Storage settings'))
      ->setDescription(t('Settings every bundle using this field shares, described by the field type itself.'));
  }

}
