<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Resource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool\FieldStorageSettingsSchemaVariants;
use Drupal\data_surface_tool\Storage\FieldStorageStorage;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;

/**
 * What every bundle using a field shares: its storage.
 *
 * Attached by FieldInstanceResource at `storage`, and edited on its own.
 * Its identity is core's for a field storage: the entity type and the
 * field's machine name, then the field type, which follows from them and
 * chooses the settings. On its own, its update names the entity type and
 * the field name, `/surface-api/field.storage/node/body`. As the field's
 * child it is handed what the field knows: all three on reuse and update,
 * the entity type on create, and otherwise the field's accepted identity
 * when it is committed. Nothing here depends on anything entered, so
 * there is no #[RefinesInput] method.
 *
 * The settings are variants the field type chooses between, as the
 * field's own settings are: a field type's module marks a storage
 * settings class #[Variant(of: FieldStorageResource::class, at: 'settings')],
 * and every UI field type without one gets a variant derived from its
 * config schema, `field.storage_settings.<field type>`.
 *
 * @code
 *                     add            edit
 *   entity_type       the field's    locked
 *   field_name        the field's    locked
 *   field_type        open           locked
 *   cardinality       open           open; may not shrink once there is data
 *   translatable      open           open
 *   settings          by type        fixed by the known type; the schema may
 *                                    not change once there is data
 * @endcode
 */
#[Resource('field.storage',
  identity: ['entity_type', 'field_name', 'field_type'],
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
  #[Operation('create',
    label: new TranslatableMarkup('New field storage'),
    description: new TranslatableMarkup('Creates the storage of a field being added; asked only by the field that uses it.'),
    permission: 'administer %entity_type fields',
    creates: TRUE,
  )]
  public static function create(): OperationContext {
    return new OperationContext('create', creates: TRUE);
  }

  /**
   * An existing field storage, named by its entity type and field name.
   *
   * Once the field holds data, cardinality may not shrink. That depends
   * on the storage, not on anything entered, so the operation says it,
   * with a constraint. Core's real rule counts the deltas in use;
   * simplified here to "no fewer than now", and to "unlimited stays
   * unlimited". A settings change the database could not take with data
   * in it is the storage class's to refuse, at prepare, because only the
   * field storage knows which settings reach the schema.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the field name names nothing, saying which.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a field storage'),
    description: new TranslatableMarkup('Changes a field storage, named by its entity type and field name; once the field holds data, its number of values may not shrink.'),
    permission: 'administer %entity_type fields',
  )]
  public static function update(string $entity_type, string $field_name): OperationContext {
    $storage = self::load($entity_type, $field_name);
    $context = new OperationContext('update', known: [
      'entity_type' => $entity_type,
      'field_name' => $field_name,
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
   * Loads the storage an entity type and a field name name.
   *
   * @param string $entity_type
   *   The entity type's machine name.
   * @param string $field_name
   *   The field's machine name.
   *
   * @return \Drupal\field\FieldStorageConfigInterface
   *   The storage.
   *
   * @throws \InvalidArgumentException
   *   When the entity type is no fieldable one, or has no such field,
   *   saying which part names nothing.
   */
  private static function load(string $entity_type, string $field_name): FieldStorageConfigInterface {
    $definition = \Drupal::entityTypeManager()->getDefinition($entity_type, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      throw new \InvalidArgumentException(sprintf('entity_type "%s" names no entity type that has fields.', $entity_type));
    }
    $storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    if (!$storage instanceof FieldStorageConfigInterface) {
      throw new \InvalidArgumentException(sprintf('field_name "%s" names no field of the %s entity type.', $field_name, $entity_type));
    }
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    // Which storage this is: the field's, which hands it to its storage.
    $inputs->add('entity_type', 'string', t('Entity type'))
      ->setDescription(t('The machine name of the entity type the field is on.'));

    $inputs->add('field_name', 'string', t('Machine name'))
      ->setDescription(t('The machine name of the field, for example field_tags.'));

    $inputs->add('field_type', 'string', t('Field type'))
      ->setDescription(t("The field type, which decides what the storage settings are. The same as the field's."));

    // The storage itself.
    $inputs->add('cardinality', 'integer', t('Allowed number of values'), default: 1)
      ->setDescription(t('How many values the field holds: a number of one or more, or -1 for unlimited.'))
      ->addConstraint('Range', ['min' => FieldStorageConfigInterface::CARDINALITY_UNLIMITED]);

    $inputs->add('translatable', 'boolean', t('Translatable'), default: TRUE);

    // Its part.
    $inputs->attachVariants('settings', by: 'field_type', from: FieldStorageSettingsSchemaVariants::class)
      ->setLabel(t('Storage settings'))
      ->setDescription(t('Settings every bundle using this field shares, described by the field type itself.'));
  }

}
