<?php

declare(strict_types=1);

namespace Drupal\field_clone\SurfaceAlter;

use Drupal\field\FieldConfigInterface;
use Drupal\field\Surface\FieldInstanceSurface;
use Drupal\surface_sketch\Surface\Attribute\Situation;
use Drupal\surface_sketch\Surface\SurfaceContext;

/**
 * A way to ask for the field surface that the field module never wrote.
 *
 * Lives in src/SurfaceAlter because it reaches into another module's
 * surface. Needs no interface: the attribute is what is found. It
 * builds on the owner's `reuse` situation and adds what makes it a
 * clone, the values to start from.
 */
final class FieldCloneSituations {

  #[Situation('clone', label: 'Clone a field to another bundle', of: FieldInstanceSurface::class, permission: 'administer %entity_type_id fields')]
  public static function clone(FieldConfigInterface $source, string $bundle): SurfaceContext {
    return FieldInstanceSurface::reuse($source->getFieldStorageDefinition(), $bundle)
      ->withOperation('clone')
      ->withStarting([
        'label' => $source->getLabel(),
        'description' => $source->getDescription(),
        'required' => $source->isRequired(),
        'settings' => $source->getSettings(),
      ]);
  }

}
