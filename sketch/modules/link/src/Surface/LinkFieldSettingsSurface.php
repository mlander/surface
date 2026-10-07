<?php

declare(strict_types=1);

namespace Drupal\link\Surface;

use Drupal\field\Surface\FieldInstanceSurface;
use Drupal\surface_sketch\Surface\Attribute\Surface;
use Drupal\surface_sketch\Surface\Attribute\SurfaceVariant;
use Drupal\surface_sketch\Surface\ShapeInterface;
use Drupal\surface_sketch\Surface\SurfaceInterface;

/**
 * Settings only a link field has. Nothing here depends on anything.
 */
#[Surface('field.settings.link')]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'link')]
final class LinkFieldSettingsSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('link_type', 'string', 'Allowed link type', default: 'both')
      ->addConstraint('Choice', ['choices' => ['internal', 'external', 'both']]);
    $inputs->add('title', 'string', 'Allow link text', default: 'optional')
      ->addConstraint('Choice', ['choices' => ['disabled', 'optional', 'required']]);
  }

}
