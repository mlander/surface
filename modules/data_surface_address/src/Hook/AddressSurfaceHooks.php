<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\data_surface_address\Plugin\Field\FieldType\SurfaceAddressItem;

/**
 * Hook implementations for the address field surface adoption.
 *
 * A class rather than a .module file: core 11.3 discovers hooks from
 * #[Hook] attributes, so a module whose only procedural code was one
 * assignment ships no procedural file.
 */
final class AddressSurfaceHooks {

  /**
   * Implements hook_field_info_alter().
   *
   * The whole adoption, in one assignment. The subclass extends
   * \Drupal\address\Plugin\Field\FieldType\AddressItem and changes
   * nothing about how an address is stored, validated or rendered; it
   * replaces fieldSettingsForm() with the generated one, and it is
   * reachable as the parent class of the swapped one, so the original
   * form is one get_parent_class() away and uninstalling this module
   * restores it.
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (isset($info['address'])) {
      $info['address']['class'] = SurfaceAddressItem::class;
    }
  }

}
