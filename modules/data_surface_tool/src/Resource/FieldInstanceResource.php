<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Resource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool\Access\FieldInstanceAccess;
use Drupal\data_surface_tool\FieldSettingsSchemaVariants;
use Drupal\data_surface_tool\Storage\FieldInstanceStorage;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\field\FieldStorageConfigInterface;

/**
 * A field on a bundle. Generic: knows nothing of any field type.
 *
 * @code
 *   FieldInstanceResource
 *   -> storage    FieldStorageResource
 *   -> settings   whichever class is marked as the variant for the field type
 * @endcode
 *
 * The storage is a fixed child with storage of its own. The settings
 * are variants the field type chooses between: the field resource names
 * no children, and each field type's module marks its own settings class
 * (a resource or a structure) with #[Variant]. A field type offered in
 * the UI with no settings class still has a variant, the one
 * FieldSettingsSchemaVariants derives from its config schema.
 *
 * Create, reuse and update are not three resources. They are how much is
 * already known, so they are the three ways to ask for this resource, below.
 *
 * @code
 *                              create   reuse    update
 *   entity_type_id, bundle     locked   locked   locked
 *   field_type, field_name     open     locked   locked
 *   label, description, ...    open     open     open, current values loaded
 *   storage, its own           create   update   update
 *   settings                   by type  fixed by the known type
 * @endcode
 */
#[Resource('field.instance',
  identity: ['entity_type_id', 'bundle', 'field_name', 'field_type'],
  storage: FieldInstanceStorage::class,
  access: FieldInstanceAccess::class,
)]
final class FieldInstanceResource implements ResourceInterface {

  /**
   * Adds a field to a bundle, with a storage of its own.
   *
   * The caller names the field, so the same call twice leaves what the
   * first left: idempotent, though it creates.
   */
  #[Operation('create',
    label: new TranslatableMarkup('Add a field to a bundle'),
    description: new TranslatableMarkup('Adds a new field, with a storage of its own, to a bundle of an entity type, under a machine name the caller chooses.'),
    permission: 'administer %entity_type_id fields',
    creates: TRUE,
    idempotent: TRUE,
  )]
  public static function create(string $entity_type_id, string $bundle): OperationContext {
    return self::handSettingsTheField(new OperationContext('create', creates: TRUE, known: [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
    ]));
  }

  /**
   * Adds an existing storage's field to another bundle.
   *
   * A create for the field, an update for its storage: parent and child
   * see different operations. The caller names the field, as for create.
   */
  #[Operation('reuse',
    label: new TranslatableMarkup('Add an existing field to another bundle'),
    description: new TranslatableMarkup('Adds a field whose storage already exists to another bundle of the same entity type, sharing that storage.'),
    permission: 'administer %entity_type_id fields',
    creates: TRUE,
    idempotent: TRUE,
  )]
  public static function reuse(FieldStorageConfigInterface $storage, string $bundle): OperationContext {
    return self::handSettingsTheField(self::create($storage->getTargetEntityTypeId(), $bundle)
      ->withId('reuse')
      ->withKnown(['field_type' => $storage->getType(), 'field_name' => $storage->getName()]))
      ->withChild('storage', FieldStorageResource::update($storage));
  }

  /**
   * Edits a field. Everything that says which field it is, is known.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a field'),
    description: new TranslatableMarkup('Changes a field and its storage; the field is named by its id, which stays as it is.'),
    permission: 'administer %entity_type_id fields',
  )]
  public static function update(FieldConfigInterface $field): OperationContext {
    $storage = $field->getFieldStorageDefinition();
    assert($storage instanceof FieldStorageConfigInterface);
    return self::handSettingsTheField(self::reuse($storage, $field->getTargetBundle())
      ->withId('update', creates: FALSE));
  }

  /**
   * Hands the settings child the field it belongs to, as its identity.
   *
   * The one door in the wall between a parent and its child: the child
   * never reads the parent's values, so what it needs to load and store
   * by is given to it in its own context.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The field's context.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The same context, with one for the settings.
   */
  private static function handSettingsTheField(OperationContext $context): OperationContext {
    return $context->withChild('settings', new OperationContext(
      $context->id,
      $context->creates,
      array_intersect_key($context->known, array_flip(['entity_type_id', 'bundle', 'field_name'])),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    // Which field this is.
    $inputs->add('entity_type_id', 'string', t('Entity type'))->setRequired(TRUE)
      ->setDescription(t('The machine name of the entity type the field is on.'))
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => FieldableEntityInterface::class,
      ]);

    $inputs->add('bundle', 'string', t('Bundle'))->setRequired(TRUE)
      ->setDescription(t('The bundle the field is on, for example article or page.'));

    $inputs->add('field_type', 'string', t('Field type'))->setRequired(TRUE)
      ->setDescription(t('The field type, which decides what the settings are.'));

    // What a field storage's name may be, which its constructor refuses
    // otherwise with an exception rather than a violation.
    $inputs->add('field_name', 'string', t('Machine name'))->setRequired(TRUE)
      ->setDescription(t('The machine name of the field storage, for example field_tags.'))
      ->addConstraint('Length', ['max' => FieldStorageConfig::NAME_MAX_LENGTH])
      ->addConstraint('Regex', [
        'pattern' => '/^[_a-z]+[_a-z0-9]*$/D',
        'message' => t('The machine name must start with a lowercase letter or underscore, and contain only lowercase letters, numbers and underscores.'),
      ]);

    // The field itself.
    $inputs->add('label', 'string', t('Label'))->setRequired(TRUE)
      ->setDescription(t('The human readable label for the field on this bundle.'));

    $inputs->add('description', 'string', t('Help text'))
      ->setDescription(t('Help text to display for the field.'));

    $inputs->add('required', 'boolean', t('Required field'), default: FALSE)
      ->setDescription(t('Whether the field is required.'));

    // Its parts.
    $inputs->attach('storage', FieldStorageResource::class)
      ->setLabel(t('Field storage'))
      ->setDescription(t('What every bundle using this field shares.'));

    $inputs->attachVariants('settings', by: 'field_type', from: FieldSettingsSchemaVariants::class)
      ->setLabel(t('Field settings'))
      ->setDescription(t('Settings for this field on this bundle, described by the field type itself.'));
  }

  /**
   * The bundle must belong to the chosen entity type.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type_id): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type_id]);
  }

}
