<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface_tool\SurfaceFieldSettingsTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\InputDefinitionRefinerInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates a field instance through the field type's own surface.
 *
 * The inputs mirror the free-form field update tool one for one. What
 * differs, besides the described settings input, is what a partial
 * payload means. The free-form tool merges the settings it is given over
 * the stored ones after stripping every null, so a key can be set but
 * never cleared. Here the pipeline merges over what the target loads,
 * key by key and at every depth, and a null is a value like any other:
 * sending one key changes that key alone, and sending a key as null
 * clears it. That is deliberate, and it is what makes "remove the
 * override on this one property" expressible at all.
 */
#[Tool(
  id: 'data_surface:field_update',
  label: new TranslatableMarkup('Update field on bundle'),
  description: new TranslatableMarkup('Updates a field instance on a bundle, with the settings the field type describes. Settings are merged over the stored ones, and a setting sent as null is cleared.'),
  operation: ToolOperation::Write,
  input_definitions: [
    'entity_type_id' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity type ID'),
      description: new TranslatableMarkup('The machine name of the entity type.'),
      constraints: [
        'PluginExists' => [
          'manager' => 'entity_type.manager',
          'interface' => ContentEntityInterface::class,
        ],
      ],
    ),
    'bundle' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      description: new TranslatableMarkup('The bundle containing the field, for example article or page.'),
    ),
    'field_name' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field name'),
      description: new TranslatableMarkup('The machine name of the field to update, for example field_tags.'),
    ),
    'label' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field label'),
      description: new TranslatableMarkup('The human readable label for the field.'),
      required: FALSE,
    ),
    'description' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field description'),
      description: new TranslatableMarkup('Help text to display for the field.'),
      required: FALSE,
    ),
    'required' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Required'),
      description: new TranslatableMarkup('Whether the field is required.'),
      required: FALSE,
    ),
    'settings' => new MapInputDefinition(
      label: new TranslatableMarkup('Field settings'),
      description: new TranslatableMarkup('Field instance settings to update, not storage settings. Once the entity type, bundle and field name are known this is replaced by the settings the field type describes, or by its config schema when it describes none.'),
      required: FALSE,
    ),
  ],
  input_definition_refiners: [
    'bundle' => ['entity_type_id'],
    'field_name' => ['entity_type_id', 'bundle'],
    'settings' => ['entity_type_id', 'bundle', 'field_name'],
  ],
  // Declared by hand, and both halves of that are deliberate. The
  // #[Tool] attribute takes outputs statically and offers no
  // output_definition_refiners beside its input_definition_refiners, so
  // there is no declared way to say "this output is whatever the field
  // type describes"; and what is emitted here is the *stored* settings,
  // which is storage shape and belongs to the target, not the surface
  // shape SurfaceInputDefinitions converts. Describing it from the
  // surface would advertise a shape this tool does not hand back.
  output_definitions: [
    'settings' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Stored settings'),
      description: new TranslatableMarkup('The field instance settings as they were stored.'),
    ),
  ],
)]
class FieldUpdate extends ToolBase implements InputDefinitionRefinerInterface {

  use SurfaceFieldSettingsTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fieldTypePluginManager = $container->get('plugin.manager.field.field_type');
    $instance->typedConfigManager = $container->get('config.typed');
    $instance->surfaceLocator = $container->get('data_surface_tool.field_surface_locator');
    $instance->surfaceInputDefinitions = $container->get('data_surface_tool.input_definitions');
    $instance->pipeline = $container->get('data_surface.pipeline');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'label' => $label,
      'description' => $description,
      'required' => $required,
      'settings' => $settings,
    ] = $values;

    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return ExecutableResult::failure($this->t('Entity type "@type" does not exist.', [
        '@type' => $entity_type_id,
      ]));
    }
    $field = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
    if (!$field instanceof FieldConfig) {
      return ExecutableResult::failure($this->t('Field @field does not exist on bundle @bundle of entity type @type.', [
        '@field' => $field_name,
        '@bundle' => $bundle,
        '@type' => $entity_type_id,
      ]));
    }
    // Asked again here, not only in checkAccess(): execute() is callable
    // from PHP without an access check, and this is the method that
    // writes. The field type's own answer is part of it, and the same
    // answer travels into the pipeline below, so the settings write is
    // gated by the object the tool already consulted rather than by a
    // second resolution that could disagree with this one.
    $access = $this->fieldSettingsAccess($field, $this->currentUser);
    if (!$access->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to update this field.'));
    }

    $updated = [];
    if ($label !== NULL) {
      $field->setLabel($label);
      $updated[] = 'label';
    }
    if ($description !== NULL) {
      $field->setDescription($description);
      $updated[] = 'description';
    }
    if ($required !== NULL) {
      $field->setRequired($required);
      $updated[] = 'required';
    }

    if (is_array($settings)) {
      $surface = $this->surfaceLocator->surfaceFor($field);
      $target = $surface === NULL ? NULL : $this->surfaceLocator->targetFor($field);
      if ($surface !== NULL && $target !== NULL) {
        // The target holds the field this method already set the label
        // and the rest on, so its commit saves those too and the field
        // is written once.
        $result = $this->pipeline->submit($surface, $settings, $target, access: $access);
        if (!$result->isValid()) {
          return ExecutableResult::failure($this->t('The settings for field @field were refused: @violations', [
            '@field' => $field_name,
            '@violations' => $this->violationSummary($result->violations),
          ]));
        }
        $updated[] = 'settings';
        return $this->updatedResult($field, $field_name, $bundle, $updated, FALSE);
      }
      if ($settings !== []) {
        $class = $this->fieldTypePluginManager->getDefinitions()[$field->getType()]['class'];
        $field->setSettings($class::fieldSettingsFromConfigData($settings) + $field->getSettings());
        $updated[] = 'settings';
      }
    }

    return $this->updatedResult($field, $field_name, $bundle, $updated, TRUE);
  }

  /**
   * Saves the field when anything changed, and reports what changed.
   *
   * @param \Drupal\field\Entity\FieldConfig $field
   *   The field being updated.
   * @param string $field_name
   *   The field machine name, for the message.
   * @param string $bundle
   *   The bundle, for the message.
   * @param string[] $updated
   *   The names of what changed.
   * @param bool $save
   *   Whether this method still owns the write. FALSE when the pipeline
   *   has already committed the field.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function updatedResult(FieldConfig $field, string $field_name, string $bundle, array $updated, bool $save): ExecutableResult {
    if ($updated === []) {
      return ExecutableResult::success($this->t('No changes made to field @field.', [
        '@field' => $field_name,
      ]), ['settings' => $field->getSettings()]);
    }
    if ($save) {
      $field->save();
    }
    return ExecutableResult::success($this->t('Updated field @field on bundle @bundle. Updated: @updated', [
      '@field' => $field_name,
      '@bundle' => $bundle,
      '@updated' => implode(', ', $updated),
    ]), ['settings' => $field->getSettings()]);
  }

  /**
   * {@inheritdoc}
   *
   * The field config entity the values name is what answers, so the
   * permission is spelled where core spells it rather than assembled
   * here out of input, and a module refining field access through the
   * entity access hooks is heard. The field type's surface is asked on
   * top of that, and may refuse what the entity allowed; it cannot allow
   * what the entity refused.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = $this->fieldUpdateAccess($values, $account);

    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function refineInputDefinition(string $name, InputDefinitionInterface $definition, array $values): InputDefinitionInterface {
    switch ($name) {
      case 'bundle':
        $definition->addConstraint('EntityBundleExists', ['entityTypeId' => $values['entity_type_id']]);
        break;

      case 'field_name':
        $definition->addConstraint('FieldExists', [
          'entityTypeId' => $values['entity_type_id'],
          'bundle' => $values['bundle'],
        ]);
        break;

      case 'settings':
        $field = FieldConfig::loadByName($values['entity_type_id'], $values['bundle'], $values['field_name']);
        if ($field instanceof FieldConfig) {
          $definition = $this->settingsInputDefinition($field, $definition, NULL);
        }
        break;
    }

    return $definition;
  }

}
