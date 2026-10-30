<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a plugin class: its configuration is this structure.
 *
 * Two spellings. Naming a class, #[UsesStructure(DemoBlockStructure::class)],
 * points at a structure in src/Structure like any other, so it reads the
 * same and alters find it the same way. With no argument, #[UsesStructure],
 * the plugin class is its own structure: it implements StructureInterface,
 * with its static defineInputs() and its static #[RefinesInput] methods
 * beside the plugin's own code, and the host builds it from the plugin
 * class. Such a structure is found through the plugin definitions rather
 * than a directory; its id is `<host type>:<plugin id>` unless the class
 * also carries #[Structure], and an alter names it by the plugin class.
 *
 * Either way the plugin host (block, formatter, condition, ...) supplies
 * the operation and the storage, because only it holds the plugin
 * instance.
 *
 * Being on the plugin's own attributes, it is in the plugin definition,
 * so a tool can list which plugins have a structure without
 * instantiating any. Core's plugin discovery reads only the plugin
 * type's own attribute, so this one is copied into the definition, under
 * DEFINITION_KEY, by the plugin type's definition alter hook: the
 * structure class it names, or the plugin class itself when it names
 * none.
 *
 * @see \Drupal\surface\Hook\ResourcePluginHooks
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsesStructure {

  /**
   * The plugin definition key the structure class is recorded under.
   */
  public const DEFINITION_KEY = 'surface';

  /**
   * Constructs a UsesStructure attribute.
   *
   * @param class-string<\Drupal\surface\Resource\DefinesInputsInterface>|null $structure
   *   The structure the plugin's configuration is, or NULL when the
   *   plugin class is its own structure.
   */
  public function __construct(public readonly ?string $structure = NULL) {}

}
