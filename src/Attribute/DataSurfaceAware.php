<?php

declare(strict_types=1);

namespace Drupal\data_surface\Attribute;

/**
 * Declares a class's surface statically.
 *
 * Stacks alongside any plugin-type attribute — discovery for the host
 * plugin type ignores attributes it does not know — so this is safe on a
 * Block, a FieldFormatter, or any other plugin today, long before the
 * host plugin type has heard of surfaces.
 *
 * PHP's new-in-initializers covers attribute arguments, so complete data
 * definitions live here. Static calls are not allowed in an attribute
 * argument, which rules out the fluent DataDefinition::create(...)
 * spelling; the constructor takes the definition array instead, and
 * every key a surface reads fits in it:
 *
 * @code
 * 'headline' => new DataDefinition([
 *   'type' => 'string',
 *   'label' => new TranslatableMarkup('Headline'),
 *   'description' => new TranslatableMarkup('Shown above the items.'),
 *   'required' => TRUE,
 *   'settings' => ['multiline' => FALSE],
 *   'constraints' => ['Length' => ['max' => 50]],
 *   'default_value' => 'Featured content',
 *   'examples' => ['Quarterly report'],
 * ]),
 * @endcode
 *
 * The last two keys are the interim spellings DefinitionMetadata reads
 * and writes until core's own default value and example methods land;
 * see PLAN.md.
 *
 * One core class does not fit: ListDataDefinition takes its item
 * definition as a constructor argument and so is declarable here, but
 * MapDataDefinition takes only its own definition array and gains its
 * property definitions through a setter. So the attribute declares the
 * flat part of such a surface and the map's properties are supplied
 * through the builder, in the callback
 * DataSurfaceFactoryInterface::buildFromClass() takes for exactly this:
 * they are set before the build event fires, so subscribers and every
 * later consumer still see one complete surface. The address field
 * type's item class is the worked example; see ADOPTION.md.
 *
 * What belongs here is decided by runtime dependence, not by structure.
 * An all-static surface — literal labels, constraints and defaults, and
 * constraints such as PluginExists whose option list is resolved live
 * from a static spelling — is declared entirely in the attribute, and
 * getDataSurface() is one call to the factory's buildFromClass(). A
 * surface that needs live site state to describe itself at all, or that
 * nests another surface, is built entirely in getDataSurface() instead.
 * One home or the other, never split: a class whose contract is half
 * here and half there has no single place to read it.
 *
 * The attribute is a declaration and only that: it reads classes and
 * answers with what they declared. Turning a declaration into a sealed,
 * altered surface needs the build event and therefore a service, so it
 * belongs to the factory.
 *
 * A fully attribute-declared surface is harvestable without
 * instantiation, which is what DataSurfaceAwareness trades on:
 * derivers, documentation, or an agent enumerating configurable things
 * read the whole contract — definitions, locks and dependency graph —
 * from the class alone.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DataSurfaceAware {

  /**
   * Constructs a DataSurfaceAware attribute.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   Data definitions keyed by surface key, when the surface is fully
   *   static. Leave empty for surfaces built at runtime.
   * @param array<string, string[]> $refinements
   *   Refinement dependencies: target definition name => the names of
   *   the sibling values it is refined against.
   * @param string[] $locked
   *   Surface keys whose value is fixed to the declared default.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $outputs
   *   Data definitions keyed by output key: what this class's execution
   *   emits, in the same vocabulary the inputs are declared in. Empty
   *   for a class that declares no outputs, which is every class that
   *   never heard of them.
   * @param array<string, string[]> $output_refinements
   *   Output refinement dependencies: output key => the names of the
   *   *input* keys it is refined against. An output never refines
   *   against another output.
   */
  public function __construct(
    public readonly array $definitions = [],
    public readonly array $refinements = [],
    public readonly array $locked = [],
    public readonly array $outputs = [],
    public readonly array $output_refinements = [],
  ) {
  }

  /**
   * Reads the attribute instance from a class, if it declares one.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return self|null
   *   The attribute instance, or NULL when the class declares none.
   */
  public static function fromClass(string $class): ?self {
    if (!class_exists($class)) {
      return NULL;
    }
    $attributes = (new \ReflectionClass($class))->getAttributes(self::class);
    return $attributes === [] ? NULL : $attributes[0]->newInstance();
  }

  /**
   * Reads a class's declared refinement map.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return array<string, string[]>
   *   The refinement map; empty when the class declares none.
   */
  public static function refinementsOf(string $class): array {
    return self::fromClass($class)->refinements ?? [];
  }

}
