<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_test\Access\GatedFieldSettingsAccess;
use Drupal\surface_examples_field\Resource\FieldInstanceResource;

/**
 * The gated test field type's settings: one note, and a gate.
 *
 * Named by SurfaceGatedItem with #[UsesStructure], so the field type host
 * builds it for Field UI, and the field instance resource's settings
 * variant for the field type, so the derived field tools
 * build it as a field's settings. No storage: a variant without one is
 * stored by the field, under its settings.
 *
 * Its access class is the fixture's point. It refuses while a state flag
 * is set and has no opinion otherwise, so a test that sees a caller
 * refused while every permission core asks for is held has seen the
 * field type's own answer refuse it, through either host.
 *
 * The reference to FieldInstanceResource is a class name and nothing
 * more: on a site without surface_examples_field it does not load, discovery
 * skips the variant, and the field type host still builds this structure.
 */
#[Structure('field.settings.surface_gated', access: GatedFieldSettingsAccess::class)]
#[Variant(of: FieldInstanceResource::class, at: 'settings', for: 'surface_gated')]
final class GatedFieldSettingsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('A note stored with this field instance.'));
  }

}
