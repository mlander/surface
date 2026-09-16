<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;

/**
 * The part of the two field tools that is about surfaces, not fields.
 *
 * Both tools refine the same settings input the same way and report
 * violations the same way, and neither of them contains a line of
 * knowledge about what a setting means: refining is a lookup plus a
 * conversion, and executing is one call to the pipeline. Everything the
 * settings could get wrong is described by the surface and enforced by
 * the pipeline, which is the point being demonstrated.
 */
trait SurfaceFieldSettingsTrait {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The service that finds a field type's surface and its target.
   *
   * @var \Drupal\data_surface_tool\FieldSurfaceLocator
   */
  protected FieldSurfaceLocator $surfaceLocator;

  /**
   * The service that converts a surface into input definitions.
   *
   * @var \Drupal\data_surface_tool\SurfaceInputDefinitions
   */
  protected SurfaceInputDefinitions $surfaceInputDefinitions;

  /**
   * The pipeline that accepts, validates, prepares and commits values.
   *
   * @var \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   */
  protected DataSurfacePipelineInterface $pipeline;

  /**
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected FieldTypePluginManagerInterface $fieldTypePluginManager;

  /**
   * The typed config manager, which owns the config schema fallback.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * Answers whether an account may administer an entity type's fields.
   *
   * The permission name carries an entity type id and the id arrives as
   * tool input, so it is never spelled into a permission before the
   * entity type manager has confirmed the entity type exists. An id
   * naming nothing is refused outright rather than turned into a
   * permission string no role can hold, which would read to a site
   * builder as a permission waiting to be granted. This is the answer
   * for adding a field, where there is no field config entity yet to ask;
   * once one exists, fieldUpdateAccess() asks the entity itself.
   *
   * @param mixed $entity_type_id
   *   The entity type id as it arrived, not yet known to be a string or
   *   to name anything.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, forbidden when the entity type does not exist.
   */
  protected function fieldAdministrationAccess(mixed $entity_type_id, AccountInterface $account): AccessResultInterface {
    if (!is_string($entity_type_id) || $entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'administer ' . $entity_type_id . ' fields');
  }

  /**
   * Answers whether an account may update the field the values name.
   *
   * The field config entity is asked, not a permission assembled here:
   * core's own access handler delegates to the field storage, which is
   * where "administer <entity type> fields" is spelled once, and any
   * module refining that answer through entity access hooks is heard.
   * Values naming no field are refused with no reason attached, so the
   * refusal says nothing about whether the field exists.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function fieldUpdateAccess(array $values, AccountInterface $account): AccessResultInterface {
    $field = $this->resolveField($values);
    return $field === NULL
      ? AccessResult::forbidden()
      : $this->fieldSettingsAccess($field, $account);
  }

  /**
   * Answers whether an account may configure one field's settings.
   *
   * Two answers, and the field type gets the second one. The entity's
   * own answer is the host gate and stays exactly what it was; the field
   * type's surface may refuse on top of it, because a field type can
   * know something about its settings that no generic field permission
   * expresses — a locked instance, a setting only a site owner may
   * touch. What it cannot do is open a door the entity closed, which is
   * the rule DataSurfaceAccess::gate() holds both halves to, neutral
   * included: a field type with nothing to say leaves the entity's
   * answer untouched.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field instance, saved or not.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The combined access result.
   */
  protected function fieldSettingsAccess(FieldConfigInterface $field, AccountInterface $account): AccessResultInterface {
    return DataSurfaceAccess::gate(
      $field->access('update', $account, TRUE),
      $this->surfaceLocator->accessFor($field, account: $account),
    );
  }

  /**
   * Answers whether an account may add a field with these settings.
   *
   * The add counterpart: there is no saved field to ask, so the host
   * gate is the entity type's field administration permission and the
   * field type is asked about the instance that is about to exist. A
   * payload naming no existing field storage describes nothing the field
   * type could have an opinion on, so only the host gate answers.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The combined access result.
   */
  protected function fieldAddAccess(array $values, AccountInterface $account): AccessResultInterface {
    $host = $this->fieldAdministrationAccess($values['entity_type_id'] ?? NULL, $account);
    $field = $this->unsavedField($values);
    return $field === NULL
      ? $host
      : DataSurfaceAccess::gate($host, $this->surfaceLocator->accessFor($field, account: $account));
  }

  /**
   * Builds the unsaved field instance a payload describes, if it can.
   *
   * The same object the settings input definition is refined against: a
   * field config that has never been saved is still a complete subject
   * for both the surface and the access answer, which is what lets a
   * tool describe and gate a field before it exists.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The unsaved field, or NULL when the values name no field storage.
   */
  protected function unsavedField(array $values): ?FieldConfigInterface {
    foreach (['entity_type_id', 'bundle', 'field_name'] as $key) {
      if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
        return NULL;
      }
    }
    if (!$this->entityTypeManager->hasDefinition($values['entity_type_id'])) {
      return NULL;
    }
    $storage = FieldStorageConfig::loadByName($values['entity_type_id'], $values['field_name']);
    return $storage === NULL ? NULL : FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $values['bundle'],
    ]);
  }

  /**
   * Loads the field instance the values name, if the values name one.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the values are not three non-empty
   *   strings naming an existing entity type and an existing field.
   */
  protected function resolveField(array $values): ?FieldConfigInterface {
    foreach (['entity_type_id', 'bundle', 'field_name'] as $key) {
      if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
        return NULL;
      }
    }
    if (!$this->entityTypeManager->hasDefinition($values['entity_type_id'])) {
      return NULL;
    }
    $field = FieldConfig::loadByName($values['entity_type_id'], $values['bundle'], $values['field_name']);
    return $field instanceof FieldConfigInterface ? $field : NULL;
  }

  /**
   * Builds the settings input definition for one field instance.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field instance whose settings are being described. It need not
   *   be saved; an unsaved one describes a field about to be added.
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $advertised
   *   The unrefined definition, returned unchanged when neither a
   *   surface nor a config schema can say anything better.
   * @param mixed $default_value
   *   The default for the settings map as a whole.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The refined definition.
   */
  protected function settingsInputDefinition(FieldConfigInterface $field, InputDefinitionInterface $advertised, mixed $default_value): InputDefinitionInterface {
    $surface = $this->surfaceLocator->surfaceFor($field);
    if ($surface !== NULL) {
      return $this->surfaceInputDefinitions->fromSurface(
        $surface,
        new TranslatableMarkup('Field instance settings'),
        new TranslatableMarkup('Settings for this field on this bundle, described by the field type itself: every key, its meaning, the values it allows and what it starts from.'),
        FALSE,
        $default_value,
      );
    }
    return $this->schemaSettingsInputDefinition($field->getType(), $default_value) ?? $advertised;
  }

  /**
   * Derives a settings definition from config schema, as a fallback.
   *
   * What a caller gets for a field type that declares no surface: the
   * serialized shape of the stored settings, with whatever the schema
   * happens to label, and no vocabulary, defaults or bounds.
   *
   * @param string $field_type
   *   The field type plugin identifier.
   * @param mixed $default_value
   *   The default for the settings map as a whole.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface|null
   *   The definition, or NULL when the field type has no settings
   *   schema either.
   */
  protected function schemaSettingsInputDefinition(string $field_type, mixed $default_value): ?InputDefinitionInterface {
    $typed_config = $this->typedConfigManager;
    $config_id = 'field.field_settings.' . $field_type;
    if (!$typed_config->hasConfigSchema($config_id)) {
      return NULL;
    }
    $definition = InputDefinition::fromConfigSchema($typed_config->getDefinition($config_id));
    $definition->setRequired(FALSE);
    $definition->setDefaultValue($default_value);
    return $definition;
  }

  /**
   * Turns pipeline violations into one message naming every path.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   The violations, exactly as the pipeline reports them.
   *
   * @return string
   *   The violations as one line, each prefixed by its full path. This
   *   is the boundary a message object stops being one: a tool result
   *   carries a single string, so the objects are rendered here and
   *   nowhere earlier.
   */
  protected function violationSummary(ViolationSet $violations): string {
    $lines = [];
    foreach ($violations as $violation) {
      $lines[] = $violation->fullPath() . ': ' . (string) $violation->message;
    }
    return implode(' ', $lines);
  }

}
