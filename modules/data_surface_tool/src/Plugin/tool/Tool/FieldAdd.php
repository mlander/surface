<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface_tool\SurfaceFieldSettingsTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
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
 * Adds a field instance whose settings come from the field type's surface.
 *
 * The inputs mirror the free-form field add tool one for one, so the two
 * can be asked the same question with the same values and the only
 * difference between the answers is where the settings input came from.
 * Here it comes from the field type itself: the surface names every
 * setting, says what it means, which values it allows and what it starts
 * from, and the pipeline enforces exactly that. A field type that
 * declares no surface falls back to the serialized config schema, which
 * is what the free-form tool offers for every field type.
 */
#[Tool(
  id: 'data_surface:field_add',
  label: new TranslatableMarkup('Add field to bundle'),
  description: new TranslatableMarkup('Adds a field instance to a bundle using an existing field storage, with the settings the field type describes.'),
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
      description: new TranslatableMarkup('The bundle to add the field to, for example article or page.'),
    ),
    'field_name' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field name'),
      description: new TranslatableMarkup('The machine name of an existing field storage, for example field_tags.'),
    ),
    'label' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field label'),
      description: new TranslatableMarkup('The human readable label for the field on this bundle.'),
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
      default_value: FALSE,
    ),
    'settings' => new MapInputDefinition(
      label: new TranslatableMarkup('Field settings'),
      description: new TranslatableMarkup('Field instance settings, not storage settings. Once the entity type, bundle and field name are known this is replaced by the settings the field type describes, or by its config schema when it describes none.'),
      required: FALSE,
      default_value: [],
    ),
  ],
  input_definition_refiners: [
    'bundle' => ['entity_type_id'],
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
class FieldAdd extends ToolBase implements InputDefinitionRefinerInterface {

  use SurfaceFieldSettingsTrait;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityDisplayRepository = $container->get('entity_display.repository');
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
    // Asked again here, not only in checkAccess(): execute() is callable
    // from PHP without an access check, and this is the method that
    // writes. The field type's own answer about the instance being added
    // is part of it, and the same answer travels into the pipeline
    // below, so the settings write is gated by the object the tool
    // already consulted rather than by a second resolution.
    $access = $this->fieldAddAccess($values, $this->currentUser);
    if (!$access->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to add fields to this entity type.'));
    }
    $field_storage = FieldStorageConfig::loadByName($entity_type_id, $field_name);
    if (!$field_storage) {
      return ExecutableResult::failure($this->t('Field storage @field does not exist for entity type @type. You must create the field storage first.', [
        '@field' => $field_name,
        '@type' => $entity_type_id,
      ]));
    }
    if (FieldConfig::loadByName($entity_type_id, $bundle, $field_name)) {
      return ExecutableResult::failure($this->t('Field @field already exists on bundle @bundle.', [
        '@field' => $field_name,
        '@bundle' => $bundle,
      ]));
    }

    $field_values = [
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required ?? FALSE,
    ];
    if (!empty($description)) {
      $field_values['description'] = $description;
    }
    $field = FieldConfig::create($field_values);

    $surface = $this->surfaceLocator->surfaceFor($field);
    $target = $surface === NULL ? NULL : $this->surfaceLocator->targetFor($field);
    // What the run asks the caller to re-choose, as opposed to what it
    // refused: empty on every ordinary save, and left out of the result
    // when it is.
    $stale = [];
    if ($surface !== NULL && $target !== NULL) {
      // One call does the whole of it: the surface says what the values
      // may be, the target says what they are stored as, and the commit
      // is the field's own save, so an invalid payload leaves no field
      // behind.
      $result = $this->pipeline->submit($surface, is_array($settings) ? $settings : [], $target, access: $access);
      if (!$result->isValid()) {
        return ExecutableResult::failure($this->t('The settings for field @field were refused: @violations', [
          '@field' => $field_name,
          '@violations' => $this->violationSummary($result->violations),
        ]));
      }
      $stale = $this->staleReferences($result->violations);
    }
    else {
      if (!empty($settings)) {
        // The fallback path is the free-form one: the input follows the
        // serialized config schema, and the field config entity wants
        // the simplified shape the field type reads back.
        $class = $this->fieldTypePluginManager->getDefinitions()[$field_storage->getType()]['class'];
        $field->setSettings($class::fieldSettingsFromConfigData($settings));
      }
      $field->save();
    }

    $this->entityDisplayRepository->getFormDisplay($entity_type_id, $bundle, 'default')
      ->setComponent($field_name, [])
      ->save();
    $this->entityDisplayRepository->getViewDisplay($entity_type_id, $bundle, 'default')
      ->setComponent($field_name, [])
      ->save();

    return ExecutableResult::success($this->t('Added field @field to bundle @bundle of entity type @type.', [
      '@field' => $field_name,
      '@bundle' => $bundle,
      '@type' => $entity_type_id,
    ]), ['settings' => $field->getSettings()] + ($stale === [] ? [] : ['stale' => $stale]));
  }

  /**
   * {@inheritdoc}
   *
   * There is no saved field config entity to ask yet, so the host gate
   * is the entity type's own field administration permission — and only
   * once the entity type manager has confirmed the entity type exists,
   * so that no permission name is assembled out of unchecked input. The
   * field type is then asked about the instance that is about to exist,
   * and may refuse what the permission allowed.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = $this->fieldAddAccess($values, $account);

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

      case 'settings':
        $field_storage = FieldStorageConfig::loadByName($values['entity_type_id'], $values['field_name']);
        if ($field_storage) {
          // The field about to be added, described before it exists.
          $field = FieldConfig::create([
            'field_storage' => $field_storage,
            'bundle' => $values['bundle'],
          ]);
          $definition = $this->settingsInputDefinition($field, $definition, []);
        }
        break;
    }
    return $definition;
  }

}
