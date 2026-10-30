<?php

declare(strict_types=1);

namespace Drupal\surface_tool;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\surface\Contract\ConfigSchemaDocument;
use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Every field type the site offers in its UI, each its settings' schema.
 *
 * Lists FieldInstanceResource's settings variants, named by its
 * `attachVariants('settings', by: 'field_type', from: ...)`: one entry
 * per field type offered in the UI, labeled with the field type's label,
 * its settings the config schema `field.field_settings.<field type>`,
 * written as JSON Schema (ConfigSchemaDocument) for the schema reader,
 * with the field type's own default settings where their type fits and
 * they say something: an empty string is a form's "nothing yet", which a
 * key its storage always holds could not be sent, so it is no default. So a
 * plain `string` or `integer` field can be added and edited through the
 * field tools as an address field can. No vocabulary, no bounds, no
 * refinement: a field type that wants those writes a settings class and
 * marks it #[Variant], and that variant wins for its value.
 *
 * It is a list the site decides, as any provider's is, so it counts
 * against `surface.settings:site_list_limit`: on a site offering
 * more field types than that, the settings key is stated open and
 * `deferred`, its variant fetched per field type. Which field types there
 * are follows from the modules installed, so the list carries the field
 * type plugin manager's cacheability and the extension list's tag.
 *
 * The field's storage has settings of its own, chosen by the same field
 * type and described the same way: FieldStorageSettingsSchemaVariants
 * is this class pointed at the storage's settings schema and defaults.
 *
 * Decision: see docs/decisions.md#derived-variants.
 */
class FieldSettingsSchemaVariants implements SiteListInterface {

  /**
   * The config schema of a field type's settings, less the field type.
   */
  protected const SCHEMA = 'field.field_settings.';

  /**
   * The field item's static method that answers the default settings.
   */
  protected const DEFAULTS = 'defaultFieldSettings';

  /**
   * The list, once read in this request.
   */
  protected ?SiteList $list = NULL;

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypes
   *   The field type plugin manager, which lists the field types.
   * @param \Drupal\surface\Contract\ConfigSchemaDocument $schemas
   *   Writes a field type's settings schema as JSON Schema.
   */
  public function __construct(
    protected readonly FieldTypePluginManagerInterface $fieldTypes,
    protected readonly ConfigSchemaDocument $schemas,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    if ($this->list !== NULL) {
      return $this->list;
    }
    $list = (new SiteList())->addCacheTags(['config:core.extension']);
    if ($this->fieldTypes instanceof CacheableDependencyInterface) {
      $list->addCacheableDependency($this->fieldTypes);
    }
    foreach ($this->fieldTypes->getDefinitions() as $type => $definition) {
      $type = (string) $type;
      if (!empty($definition['no_ui'])) {
        continue;
      }
      $list->add($type, $definition['label'] ?? $type, schema: $this->schemas->object(static::SCHEMA . $type, [], $this->defaults($definition['class'] ?? NULL)));
    }
    return $this->list = $list;
  }

  /**
   * Reads a field type's own default settings.
   *
   * @param mixed $class
   *   The field item class.
   *
   * @return array
   *   The defaults, less the empty strings, or none when the class cannot
   *   say.
   */
  protected function defaults(mixed $class): array {
    $method = static::DEFAULTS;
    if (!is_string($class) || !method_exists($class, $method)) {
      return [];
    }
    try {
      $defaults = $class::$method();
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($defaults) ? array_filter($defaults, static fn (mixed $value): bool => $value !== '') : [];
  }

}
