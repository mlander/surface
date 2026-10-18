<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * What a resource and a structure share: what it asks for.
 *
 * A class implements one of the two kinds, ResourceInterface or
 * StructureInterface, and carries the attribute of its kind; this is
 * what the build step and every caller that only needs the keys ask
 * for. How the values tighten each other is beside the keys, one
 * #[RefinesInput] method per refined key, so the class is the whole
 * contract. One that also answers with something adds
 * HasOutputsInterface.
 *
 * Neither kind is ever instantiated. Its keys are a property of the class,
 * so defineInputs() is static and the build step calls it on the class;
 * its #[RefinesInput] methods are static too, and what rides along in a
 * cached form is the class name, not an object. That is also what lets
 * a plugin class be its own structure (#[UsesStructure] with no
 * argument) without anything constructing the plugin to ask.
 *
 * @see \Drupal\data_surface\ResourceBuild\ResourcesInterface::build()
 */
interface DefinesInputsInterface {

  /**
   * Inputs. Keys, types, labels, defaults, and the structures attached.
   *
   * Takes nothing but the blueprint to fill: no values, no context, no
   * services, and static, so the language holds it to that. The keys are
   * the same wherever they are asked for; what differs per operation is
   * applied afterwards by the framework. Anything that reads another
   * key's value is refinement, and is a #[RefinesInput] method.
   *
   * Labels are built with the global t(): there is no instance for a
   * translation service to be injected into, and t() returns the same
   * lazy TranslatableMarkup, which string extraction finds.
   *
   * @param \Drupal\data_surface\Resource\BlueprintInterface $inputs
   *   The input blueprint to fill.
   */
  public static function defineInputs(BlueprintInterface $inputs): void;

}
