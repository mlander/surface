<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Plugin\Field\FieldType;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Target\FieldSettingsTarget;
use Drupal\data_surface_address\AddressSettingsShape;
use Drupal\data_surface_address\Surface\AddressFieldSettingsSurface;

/**
 * The address field type, with its settings form generated from a surface.
 *
 * Adoption without forking contrib, in one class and one hook: the class
 * extends the address field type and hook_field_info_alter() points the
 * 'address' plugin at it, so Field UI renders the generated settings
 * form and the address module is untouched. Everything else — the
 * columns, the property definitions, the constraints, the widgets, the
 * formatters, the accessors other code reads the settings through —
 * comes from the parent unchanged, because the storage contract is
 * unchanged. Only the way the settings are described changed.
 *
 * The contract itself is AddressFieldSettingsSurface, in the new
 * spelling: a class in src/Surface, which is also the variant that
 * fills the field instance surface's open settings slot for this field
 * type. This item is its host for Field UI. It builds that surface
 * through the build step in its own context and supplies the target,
 * because only the item holds the field config entity Field UI is
 * editing, unsaved changes and all.
 *
 * The distance between the surface's input shape and the stored shape
 * is AddressSettingsShape, handed to the field settings target here and
 * used by the surface's own target, AddressFieldSettingsTarget, for
 * callers that address the field by its identity instead.
 *
 * The static default field settings stay the parent's. They include the
 * deprecated 'fields' key, which the surface deliberately does not
 * describe, so reading them from the surface would drop a key the
 * address module's own accessors still look for.
 */
class SurfaceAddressItem extends AddressItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * The host identifier the build event sees for this surface.
   *
   * Namespaced by host type, as every host id is, so a subscriber
   * matching on it cannot pick up a block or a formatter that happens to
   * be called "address".
   */
  protected const HOST_ID = 'field_type:address';

  /**
   * {@inheritdoc}
   */
  public function getFieldSurface(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface {
    // The field item is bound to one field config entity, so it is its
    // own subject and a caller naming another has the wrong item.
    $this->surfaceSelfSubject($subject);
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch names a plugin's surface with #[UsesSurface], but no field type host reads it (SurfacePluginHooks serves blocks) and this class is swapped in under an annotation-discovered plugin; the item builds its surface through the build step by class, in a bare context whose operation is the host's verb.
    return $this->surfaces()->build(AddressFieldSettingsSurface::class, $this->surfaceContext($operation), static::class, self::HOST_ID);
  }

  /**
   * {@inheritdoc}
   *
   * The target carries the shape, which is what lets the surface
   * describe the input shape while the field goes on storing exactly
   * what it always stored.
   */
  public function getDataSurfaceTarget(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceTargetInterface {
    $this->surfaceSelfSubject($subject);
    return new FieldSettingsTarget($this->settingsFieldConfig(), new AddressSettingsShape());
  }

}
