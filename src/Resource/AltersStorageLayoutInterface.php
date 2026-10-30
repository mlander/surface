<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\surface\Storage\StorageLayoutInterface;

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
 * values are written: StorageLayoutInterface says why.
 *
 * @see \Drupal\surface\ResourceBuild\StorageAdapter
 * @see \Drupal\surface\SurfaceBuilderInterface::setThirdPartyLayout()
 */
interface AltersStorageLayoutInterface {

  /**
   * The layout this alter's mounted settings are stored in.
   *
   * @return \Drupal\surface\Storage\StorageLayoutInterface
   *   The layout.
   */
  public function storageLayout(): StorageLayoutInterface;

}
