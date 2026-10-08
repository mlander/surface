<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * An alter whose keys are asked for in one shape and stored in another.
 *
 * Optional, beside SurfaceAlterInterface. What the alter adds is asked
 * for the way a person says it — a deadline as an amount and a unit —
 * and stored the way its schema already says — one integer of seconds —
 * and the distance between the two is this shape, applied to the
 * alter's own mount, `third_party_settings.<module>`, and nothing else:
 * toStorage() before the target writes, fromStorage() after it reads.
 * The owner's target never has to know the contributor exists.
 *
 * The shape must be pure, because it is applied wherever the surface's
 * values are written: SettingsShapeInterface says why.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::setThirdPartyShape()
 */
interface HasStorageShapeInterface {

  /**
   * The shape this alter's mounted settings are stored in.
   *
   * @return \Drupal\data_surface\Target\SettingsShapeInterface
   *   The shape.
   */
  public function storageShape(): SettingsShapeInterface;

}
