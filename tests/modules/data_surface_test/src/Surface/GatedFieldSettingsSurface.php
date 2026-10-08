<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_test\Access\GatedFieldSettingsAccess;
use Drupal\data_surface_tool\Surface\FieldInstanceSurface;

/**
 * The gated test field type's settings: one note, and a gate.
 *
 * Named by SurfaceGatedItem with #[UsesSurface], so the field type host
 * builds it for Field UI, and the variant that fills the field instance
 * surface's settings slot for the field type, so the derived field tools
 * build it as a field's settings. No target: a variant without one is
 * stored by the field, under its settings.
 *
 * Its access class is the fixture's point. It refuses while a state flag
 * is set and has no opinion otherwise, so a test that sees a caller
 * refused while every permission core asks for is held has seen the
 * field type's own answer refuse it, through either host.
 *
 * The reference to FieldInstanceSurface is a class name and nothing
 * more: on a site without the tool bridge it does not load, discovery
 * skips the variant, and the field type host still builds this surface.
 */
#[Surface('field.settings.data_surface_gated', access: GatedFieldSettingsAccess::class)]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'data_surface_gated')]
final class GatedFieldSettingsSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', new TranslatableMarkup('Note'))
      ->setDescription(new TranslatableMarkup('A note stored with this field instance.'));
  }

}
