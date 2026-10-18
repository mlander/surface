<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\data_surface\Storage\SettingsLayoutInterface;

/**
 * An alter whose keys are asked for in one layout and stored in another.
 *
 * Optional, beside AltersInputsInterface. What the alter adds is asked
 * for the way a person says it (a deadline as an amount and a unit) and
 * stored the way its schema already says (one integer of seconds), and
 * the distance between the two is this layout, applied to the alter's
 * own mount, `third_party_settings.<module>`, and nothing else:
 * toStorage() before the storage writes, fromStorage() after it reads.
 * The owner's storage class never has to know the contributor exists.
 *
 * The layout must be pure, because it is applied wherever the altered
 * values are written: SettingsLayoutInterface says why.
 *
 * @see \Drupal\data_surface\ResourceBuild\StorageAdapter
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::setThirdPartyLayout()
 */
interface HasStorageLayoutInterface {

  /**
   * The layout this alter's mounted settings are stored in.
   *
   * @return \Drupal\data_surface\Storage\SettingsLayoutInterface
   *   The layout.
   */
  public function storageLayout(): SettingsLayoutInterface;

}
