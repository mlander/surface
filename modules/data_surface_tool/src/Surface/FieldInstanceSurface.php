<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Surface;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_tool\Access\FieldInstanceAccess;
use Drupal\data_surface_tool\Target\FieldInstanceTarget;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;

/**
 * A field on a bundle. Generic: knows nothing of any field type.
 *
 * @code
 *   FieldInstanceSurface
 *   -> storage    FieldStorageSurface
 *   -> settings   whichever surface is marked as the variant for the field type
 * @endcode
 *
 * The storage is a fixed child with a target of its own. The settings
 * are an open slot chosen by the field type: the field surface lists no
 * children, and each field type's module marks its own settings surface
 * with #[SurfaceVariant]. A field type with no settings surface in the
 * new spelling is not among the field types this surface offers,
 * because its slot would have no shape; the field tools fall back to the
 * old spelling for it.
 *
 * Add, reuse and edit are not shapes. They are how much is already
 * known, so they are the three ways to ask for this surface, below.
 *
 * @code
 *                              add      reuse    edit
 *   entity_type_id, bundle     locked   locked   locked
 *   field_type, field_name     open     locked   locked
 *   label, description, ...    open     open     open, current values loaded
 *   storage                    its add  its edit its edit
 *   settings                   by type  fixed by the known type
 * @endcode
 */
#[Surface('field.instance',
  identity: ['entity_type_id', 'bundle', 'field_name', 'field_type'],
  target: FieldInstanceTarget::class,
  access: FieldInstanceAccess::class,
)]
final class FieldInstanceSurface implements SurfaceInterface {

  /**
   * Adds a field to a bundle, with a storage of its own.
   */
  #[Situation('add', label: 'Add a field to a bundle', permission: 'administer %entity_type_id fields')]
  public static function add(string $entity_type_id, string $bundle): SurfaceContext {
    return self::handSettingsTheField(new SurfaceContext('add', creates: TRUE, known: [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
    ]));
  }

  /**
   * Adds an existing storage's field to another bundle.
   *
   * An add for the field, an edit for its storage: parent and child see
   * different operations.
   */
  #[Situation('reuse', label: 'Add an existing field to another bundle', permission: 'administer %entity_type_id fields')]
  public static function reuse(FieldStorageConfigInterface $storage, string $bundle): SurfaceContext {
    return self::handSettingsTheField(self::add($storage->getTargetEntityTypeId(), $bundle)
      ->withOperation('reuse')
      ->withKnown(['field_type' => $storage->getType(), 'field_name' => $storage->getName()]))
      ->withChild('storage', FieldStorageSurface::edit($storage));
  }

  /**
   * Edits a field. Everything that says which field it is, is known.
   */
  #[Situation('edit', label: 'Edit a field', permission: 'administer %entity_type_id fields')]
  public static function edit(FieldConfigInterface $field): SurfaceContext {
    $storage = $field->getFieldStorageDefinition();
    assert($storage instanceof FieldStorageConfigInterface);
    return self::handSettingsTheField(self::reuse($storage, $field->getTargetBundle())
      ->withOperation('edit', creates: FALSE));
  }

  /**
   * Hands the settings child the field it belongs to, as its identity.
   *
   * The one door in the wall between a parent and its child: the child
   * never reads the parent's values, so what it needs to load and store
   * by is given to it in its own context.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The field's context.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The same context, with one for the settings.
   */
  private static function handSettingsTheField(SurfaceContext $context): SurfaceContext {
    return $context->withChild('settings', new SurfaceContext(
      $context->operation,
      $context->creates,
      array_intersect_key($context->known, array_flip(['entity_type_id', 'bundle', 'field_name'])),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    // Which field this is.
    $inputs->add('entity_type_id', 'string', new TranslatableMarkup('Entity type'))->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => FieldableEntityInterface::class,
      ]);
    $inputs->add('bundle', 'string', new TranslatableMarkup('Bundle'))->setRequired(TRUE);
    $inputs->add('field_type', 'string', new TranslatableMarkup('Field type'))->setRequired(TRUE);
    $inputs->add('field_name', 'string', new TranslatableMarkup('Machine name'))->setRequired(TRUE);

    // The field itself.
    $inputs->add('label', 'string', new TranslatableMarkup('Label'))->setRequired(TRUE);
    $inputs->add('description', 'string', new TranslatableMarkup('Help text'));
    $inputs->add('required', 'boolean', new TranslatableMarkup('Required field'), default: FALSE);

    // Its parts.
    $inputs->attach('storage', FieldStorageSurface::class);
    $inputs->describe('storage',
      label: new TranslatableMarkup('Field storage'),
      description: new TranslatableMarkup('What every bundle using this field shares.'),
    );
    $inputs->attachBy('settings', by: 'field_type');
    $inputs->describe('settings',
      label: new TranslatableMarkup('Field settings'),
      description: new TranslatableMarkup('Settings for this field on this bundle, described by the field type itself.'),
    );
  }

  /**
   * The bundle must belong to the chosen entity type.
   */
  #[RefinesInput('bundle')]
  public function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type_id): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type_id]);
  }

}
