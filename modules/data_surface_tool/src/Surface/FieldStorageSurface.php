<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_tool\Target\FieldStorageTarget;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;

/**
 * What every bundle using a field shares: its storage.
 *
 * Attached by FieldInstanceSurface at `storage`. Its one identity key is
 * the field type, which chooses its settings: which storage it is, is
 * otherwise the field's identity, which the field hands it — in its own
 * context when the field's situation knows the storage, and otherwise as
 * the field's accepted identity when it is committed. Nothing here
 * depends on anything entered, so there is no #[RefinesInput] method.
 *
 * The settings are a slot chosen by the field type, as the field's own
 * settings are: a field type's module marks a storage settings surface
 * #[SurfaceVariant(of: FieldStorageSurface::class, key: 'settings')],
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
#[Surface('field.storage',
  identity: ['field_type'],
  target: FieldStorageTarget::class,
)]
final class FieldStorageSurface implements SurfaceInterface {

  /**
   * A new field storage.
   *
   * Knows nothing, so its permission cannot be named and it is refused
   * on its own: a storage is added by the field that uses it, whose
   * situation is the one asked.
   */
  #[Situation('add', label: 'New field storage', permission: 'administer %entity_type_id fields')]
  public static function add(): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE);
  }

  /**
   * An existing field storage.
   *
   * Once the field holds data, cardinality may not shrink. That depends
   * on the storage, not on anything entered, so the situation says it,
   * with a constraint. Core's real rule counts the deltas in use;
   * simplified here to "no fewer than now", and to "unlimited stays
   * unlimited". A settings change the database could not take with data
   * in it is the target's to refuse, at prepare, because only storage
   * knows which settings reach the schema.
   */
  #[Situation('edit', label: 'Edit a field storage', permission: 'administer %entity_type_id fields')]
  public static function edit(FieldStorageConfigInterface $storage): SurfaceContext {
    // The field type is identity, so it is locked; the entity type and
    // the name are no keys here, known for the target to load by.
    $context = new SurfaceContext('edit', known: [
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
  public function defineInputs(ShapeInterface $inputs): void {
    // Which storage this is, beyond what the field hands it.
    $inputs->add('field_type', 'string', new TranslatableMarkup('Field type'))
      ->setDescription(new TranslatableMarkup("The field type, which decides what the storage settings are. The same as the field's."));

    // The storage itself.
    $inputs->add('cardinality', 'integer', new TranslatableMarkup('Allowed number of values'), default: 1)
      ->setDescription(new TranslatableMarkup('How many values the field holds: a number of one or more, or -1 for unlimited.'))
      ->addConstraint('Range', ['min' => FieldStorageConfigInterface::CARDINALITY_UNLIMITED]);
    $inputs->add('translatable', 'boolean', new TranslatableMarkup('Translatable'), default: TRUE);

    // Its part.
    $inputs->attachBy('settings', by: 'field_type')
      ->setLabel(new TranslatableMarkup('Storage settings'))
      ->setDescription(new TranslatableMarkup('Settings every bundle using this field shares, described by the field type itself.'));
  }

}
