<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface;
use Drupal\data_surface_tool\Surface\FieldInstanceSurface;

/**
 * A field type's settings, read from its config schema, as a variant.
 *
 * Fills FieldInstanceSurface's settings slot for every field type a site
 * offers in its UI that no #[SurfaceVariant] fills, so a plain `string`
 * or `integer` field can be added and edited through the derived field
 * tools as an address field can. What a caller gets is what the schema
 * says: the keys of `field.field_settings.<field type>`, their types and
 * labels, and the field type's own default settings where their type
 * fits. No vocabulary, no bounds, no refinement: a field type that wants
 * those writes a settings surface and marks it #[SurfaceVariant], and
 * that variant wins.
 *
 * Read through typed config, as Tool Belt's free-form field tools read
 * it: a mapping becomes a map, a sequence a list, a primitive its typed
 * data type, and anything the schema cannot resolve without a value (a
 * dynamic type such as an entity reference's handler settings) `any`.
 */
final class FieldSettingsSchemaVariants implements DerivedVariantsInterface {

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypes
   *   The field type plugin manager, which lists the field types.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which owns the config schema.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, asked whether a schema's data type is one.
   */
  public function __construct(
    protected readonly FieldTypePluginManagerInterface $fieldTypes,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function slot(): array {
    return [FieldInstanceSurface::class, 'settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function source(): string {
    return 'the config schema `field.field_settings.<field type>`, for every field type offered in the UI';
  }

  /**
   * {@inheritdoc}
   */
  public function variants(array $declared): array {
    $variants = [];
    foreach ($this->fieldTypes->getDefinitions() as $type => $definition) {
      $type = (string) $type;
      if (!empty($definition['no_ui']) || in_array($type, $declared, TRUE)) {
        continue;
      }
      $schema = $this->typedConfig->getDefinition('field.field_settings.' . $type);
      $defaults = $this->defaults($definition['class'] ?? NULL);
      $keys = [];
      foreach ($schema['mapping'] ?? [] as $key => $property) {
        $keys[(string) $key] = $this->definition(is_array($property) ? $property : []);
        if (array_key_exists($key, $defaults) && $this->fits($keys[(string) $key], $defaults[$key])) {
          DefinitionMetadata::setDefaultValue($keys[(string) $key], $defaults[$key]);
        }
      }
      $variants[$type] = $keys;
    }
    return $variants;
  }

  /**
   * Turns one schema element into a core data definition.
   *
   * @param array $schema
   *   The element as the schema writes it: a type and what goes with it.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function definition(array $schema): DataDefinitionInterface {
    try {
      $built = $this->typedConfig->buildDataDefinition($schema, NULL);
    }
    catch (\Throwable) {
      return DataDefinition::create('any');
    }
    // A config schema definition is an array underneath; the interface
    // does not say so, every implementation typed config builds does.
    $built = $built instanceof DataDefinition ? $built->toArray() : [];
    $label = $built['label'] ?? NULL;
    $class = (string) ($built['class'] ?? '');
    if (is_a($class, Mapping::class, TRUE)) {
      $map = MapDataDefinition::create();
      foreach ($built['mapping'] ?? [] as $key => $property) {
        $map->setPropertyDefinition((string) $key, $this->definition(is_array($property) ? $property : []));
      }
      return $label === NULL ? $map : $map->setLabel($label);
    }
    if (is_a($class, Sequence::class, TRUE)) {
      $list = ListDataDefinition::create('any');
      $list->setItemDefinition($this->definition(is_array($built['sequence'] ?? NULL) ? $built['sequence'] : []));
      return $label === NULL ? $list : $list->setLabel($label);
    }
    $definition = DataDefinition::create($this->dataType($class));
    return $label === NULL ? $definition : $definition->setLabel($label);
  }

  /**
   * Reads the typed data type a schema element's class implements.
   *
   * @param string $class
   *   The class the schema names.
   *
   * @return string
   *   Its #[DataType] id when typed data knows it, `any` otherwise.
   */
  protected function dataType(string $class): string {
    if ($class === '' || !class_exists($class)) {
      return 'any';
    }
    $attribute = (new \ReflectionClass($class))->getAttributes(DataType::class)[0] ?? NULL;
    $id = $attribute?->newInstance()->getId();
    try {
      return is_string($id) && $this->typedDataManager->getDefinition($id, FALSE) !== NULL ? $id : 'any';
    }
    catch (PluginException) {
      return 'any';
    }
  }

  /**
   * Reads a field type's own default field settings.
   *
   * @param mixed $class
   *   The field item class.
   *
   * @return array
   *   The defaults, or none when the class cannot say.
   */
  protected function defaults(mixed $class): array {
    if (!is_string($class) || !method_exists($class, 'defaultFieldSettings')) {
      return [];
    }
    try {
      return $class::defaultFieldSettings();
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Says whether a default is a value of the definition's own type.
   *
   * A field type's defaults are what its form starts from, and an
   * integer setting defaulting to the empty string is a form's notion of
   * "nothing yet": declared as the key's default it would be a value the
   * key refuses.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default.
   *
   * @return bool
   *   TRUE when the default fits.
   */
  protected function fits(DataDefinitionInterface $definition, mixed $default): bool {
    return match ($definition->getDataType()) {
      'string', 'email', 'uri' => is_string($default),
      'integer' => is_int($default),
      'float' => is_int($default) || is_float($default),
      'boolean' => is_bool($default),
      'map' => is_array($default) && !array_is_list($default),
      'list' => is_array($default) && array_is_list($default),
      default => $default !== NULL,
    };
  }

}
