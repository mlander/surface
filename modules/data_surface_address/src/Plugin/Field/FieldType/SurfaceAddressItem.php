<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Plugin\Field\FieldType;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Pipeline\Storage\FieldSettingsStorage;
use Drupal\data_surface_address\AddressStorageLayout;
use Drupal\data_surface_address\Resource\AddressFieldSettingsResource;

/**
 * The address field type, with its settings form generated from a resource.
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
 * The contract itself is AddressFieldSettingsResource, in the new
 * spelling: a class in src/Resource, which is also this field type's
 * variant among the field instance resource's settings variants. This
 * item names it with #[UsesStructure] and is its host for Field UI: the
 * field type trait reads the attribute from the field type's definition
 * and builds that resource in the host's own context, and the item
 * supplies the storage, because only the item holds the field config
 * entity Field UI is editing, unsaved changes and all.
 *
 * The distance between the resource's input layout and the stored layout
 * is AddressStorageLayout, handed to the field settings storage here and
 * used by the resource's own storage, AddressFieldSettingsStorage, for
 * callers that address the field by its identity instead.
 *
 * The static default field settings stay the parent's. They include the
 * deprecated 'fields' key, which the resource deliberately does not
 * describe, so reading them from the resource would drop a key the
 * address module's own accessors still look for.
 */
#[UsesStructure(AddressFieldSettingsResource::class)]
class SurfaceAddressItem extends AddressItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * {@inheritdoc}
   *
   * The storage carries the layout, which is what lets the resource
   * describe the input layout while the field goes on storing exactly
   * what it always stored.
   */
  public function getDataSurfaceStorage(): DataSurfaceStorageInterface {
    return new FieldSettingsStorage($this->settingsFieldConfig(), new AddressStorageLayout());
  }

}
