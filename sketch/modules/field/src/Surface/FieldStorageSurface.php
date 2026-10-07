<?php

declare(strict_types=1);

namespace Drupal\field\Surface;

use Drupal\field\FieldStorageConfigInterface;
use Drupal\field\Target\FieldStorageTarget;
use Drupal\surface_sketch\Surface\Attribute\Situation;
use Drupal\surface_sketch\Surface\Attribute\Surface;
use Drupal\surface_sketch\Surface\ShapeInterface;
use Drupal\surface_sketch\Surface\SurfaceContext;
use Drupal\surface_sketch\Surface\SurfaceInterface;

/**
 * What every bundle using the field shares. Nothing here depends on
 * anything entered, so there is no #[RefinesInput] method.
 */
#[Surface('field.storage', target: FieldStorageTarget::class)]
final class FieldStorageSurface implements SurfaceInterface {

  // Where this surface is asked for.

  #[Situation('add', label: 'New field storage')]
  public static function add(): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE);
  }

  /**
   * Once the field holds data, cardinality may not shrink. That depends
   * on the storage, not on anything entered, so the situation says it.
   * (Core's real rule counts stored values; simplified here.)
   */
  #[Situation('edit', label: 'Existing field storage')]
  public static function edit(FieldStorageConfigInterface $storage): SurfaceContext {
    $context = new SurfaceContext('edit');
    return $storage->hasData()
      ? $context->withConstraint('cardinality', 'Range', ['min' => $storage->getCardinality()])
      : $context;
  }

  // What this surface is.

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('cardinality', 'integer', 'Allowed number of values', default: 1);
    $inputs->add('translatable', 'boolean', 'Translatable', default: TRUE);
  }

}
