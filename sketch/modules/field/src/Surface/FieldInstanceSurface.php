<?php

declare(strict_types=1);

namespace Drupal\field\Surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\field\Access\FieldInstanceAccess;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\field\Options\FieldableEntityTypeOptions;
use Drupal\field\Target\FieldInstanceTarget;
use Drupal\surface_sketch\Surface\Attribute\RefinesInput;
use Drupal\surface_sketch\Surface\Attribute\Situation;
use Drupal\surface_sketch\Surface\Attribute\Surface;
use Drupal\surface_sketch\Surface\HasOutputsInterface;
use Drupal\surface_sketch\Surface\ShapeInterface;
use Drupal\surface_sketch\Surface\SurfaceContext;
use Drupal\surface_sketch\Surface\SurfaceInterface;

/**
 * A field on a bundle. Generic: knows nothing of any field type.
 *
 *   FieldInstanceSurface
 *   -> storage    FieldStorageSurface
 *   -> settings   whichever surface is marked as the variant for the field type
 *
 * Add, reuse and edit are not shapes. They are how much is already known,
 * so they are the three ways to ask for this surface, below.
 */
#[Surface('field.instance',
  identity: ['entity_type_id', 'bundle', 'field_type', 'field_name'],
  target: FieldInstanceTarget::class,
  access: FieldInstanceAccess::class,
)]
final class FieldInstanceSurface implements SurfaceInterface, HasOutputsInterface {

  // Where this surface is asked for. Each knows more than the last.
  //
  //                              add      reuse    edit
  //   entity_type_id, bundle     locked   locked   locked
  //   field_type, field_name     open     locked   locked
  //   label, description, ...    open     open     open

  #[Situation('add', label: 'Add a field to a bundle', permission: 'administer %entity_type_id fields')]
  public static function add(string $entity_type_id, string $bundle): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE, known: [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
    ]);
  }

  /**
   * An add for the field, an edit for its storage: parent and child see
   * different operations.
   */
  #[Situation('reuse', label: 'Add an existing field to another bundle', permission: 'administer %entity_type_id fields')]
  public static function reuse(FieldStorageConfigInterface $storage, string $bundle): SurfaceContext {
    return self::add($storage->getTargetEntityTypeId(), $bundle)
      ->withKnown(['field_type' => $storage->getType(), 'field_name' => $storage->getName()])
      ->withChild('storage', FieldStorageSurface::edit($storage));
  }

  #[Situation('edit', label: 'Edit a field', permission: 'administer %entity_type_id fields')]
  public static function edit(FieldConfigInterface $field): SurfaceContext {
    return self::reuse($field->getFieldStorageDefinition(), $field->getTargetBundle())
      ->withOperation('edit', creates: FALSE);
  }

  // What this surface is.

  public function defineInputs(ShapeInterface $inputs): void {
    // Which field this is.
    $inputs->add('entity_type_id', 'string', 'Entity type')->setRequired(TRUE)
      ->addConstraint('OptionsList', ['source' => FieldableEntityTypeOptions::class]);
    $inputs->add('bundle', 'string', 'Bundle')->setRequired(TRUE);
    $inputs->add('field_type', 'string', 'Field type')->setRequired(TRUE);
    $inputs->add('field_name', 'string', 'Machine name')->setRequired(TRUE);

    // The field itself.
    $inputs->add('label', 'string', 'Label')->setRequired(TRUE);
    $inputs->add('description', 'string', 'Help text');
    $inputs->add('required', 'boolean', 'Required field', default: FALSE);

    // Its parts.
    $inputs->attach('storage', FieldStorageSurface::class);
    $inputs->attachBy('settings', by: 'field_type');
  }

  // How its values tighten each other.

  /**
   * The bundle must belong to the chosen entity type.
   */
  #[RefinesInput('bundle')]
  public function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type_id): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type_id]);
  }

  // What this surface answers with.

  public function defineOutputs(ShapeInterface $outputs): void {
    $outputs->add('id', 'string', 'Field id');
  }

}
