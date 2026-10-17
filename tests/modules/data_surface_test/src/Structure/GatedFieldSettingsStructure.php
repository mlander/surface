<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_test\Access\GatedFieldSettingsAccess;
use Drupal\data_surface_tool\Resource\FieldInstanceResource;

/**
 * The gated test field type's settings: one note, and a gate.
 *
 * Named by SurfaceGatedItem with #[UsesStructure], so the field type host
 * builds it for Field UI, and the variant that fills the field instance
 * resource's settings slot for the field type, so the derived field tools
 * build it as a field's settings. No storage: a variant without one is
 * stored by the field, under its settings.
 *
 * Its access class is the fixture's point. It refuses while a state flag
 * is set and has no opinion otherwise, so a test that sees a caller
 * refused while every permission core asks for is held has seen the
 * field type's own answer refuse it, through either host.
 *
 * The reference to FieldInstanceResource is a class name and nothing
 * more: on a site without the tool bridge it does not load, discovery
 * skips the variant, and the field type host still builds this structure.
 */
#[Structure('field.settings.data_surface_gated', access: GatedFieldSettingsAccess::class)]
#[Variant(of: FieldInstanceResource::class, key: 'settings', value: 'data_surface_gated')]
final class GatedFieldSettingsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('A note stored with this field instance.'));
  }

}
