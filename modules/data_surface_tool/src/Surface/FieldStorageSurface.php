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
 * Attached by FieldInstanceSurface at `storage`. It names no identity of
 * its own: which storage it is, is the field's identity, which the field
 * hands it — in its own context when the field's situation knows the
 * storage, and otherwise as the field's accepted identity when it is
 * committed. Nothing here depends on anything entered, so there is no
 * #[RefinesInput] method.
 *
 * @code
 *                     add       edit
 *   cardinality       open      open; may not shrink once there is data
 *   translatable      open      open
 * @endcode
 */
#[Surface('field.storage', target: FieldStorageTarget::class)]
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
   * simplified here, as in the sketch, to "no fewer than now", and to
   * "unlimited stays unlimited".
   */
  #[Situation('edit', label: 'Edit a field storage', permission: 'administer %entity_type_id fields')]
  public static function edit(FieldStorageConfigInterface $storage): SurfaceContext {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's storage edit knows nothing, so its target could not load; the storage's entity type, name and type are known here, as identity the surface does not declare, which the target reads and nothing locks.
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
    $inputs->add('cardinality', 'integer', new TranslatableMarkup('Allowed number of values'), default: 1)
      ->setDescription(new TranslatableMarkup('How many values the field holds: a number of one or more, or -1 for unlimited.'))
      ->addConstraint('Range', ['min' => FieldStorageConfigInterface::CARDINALITY_UNLIMITED]);
    $inputs->add('translatable', 'boolean', new TranslatableMarkup('Translatable'), default: TRUE);
  }

}
