<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\SurfaceEntry;
use Drupal\data_surface\SurfaceSlot;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Drupal\tool\TypedData\OutputDefinitionInterface;

/**
 * Turns a data surface into the Tool API's definitions.
 *
 * The whole bridge. A surface is an ordered set of
 * core data definitions; a tool input is a context definition. The two
 * describe the same thing in two vocabularies, so the conversion is a
 * rename plus the three places where the Tool API keeps its own class:
 * a map becomes a map input definition with property definitions, a list
 * becomes a list input definition with an item definition, and anything
 * else becomes a plain input definition carrying the data type.
 *
 * Nothing about any particular host, field type or setting appears here.
 * What a surface says about a value — its type, its label, its help
 * text, whether it is required, what it starts from, and which values it
 * allows — is what the converted definition says, and the Tool API's own
 * normalizer turns that into the JSON Schema an invoker advertises.
 *
 * A key the surface declared secret converts with no default value and
 * with a write-only note appended to its description; secretNote() says
 * why the note is prose rather than the JSON Schema keyword it will
 * become.
 *
 * Two things a surface knows survive the conversion only in part, and
 * both are Tool API gaps rather than choices made here:
 * - Example values. DefinitionMetadata carries them on a definition and
 *   JSON Schema has the keyword, but a context definition has nowhere to
 *   put them, so they are dropped.
 * - Type settings. Core's data definitions carry a settings array; a
 *   context definition does not, and rebuilds its data definition from
 *   the data type alone, so settings are dropped too.
 *
 * The surface's outputs convert the same way, into the output
 * definitions the Tool API declares outputs with; outputsFromSurface()
 * says what that direction loses on top of these two.
 *
 * Subsurfaces convert by what they are. An attached child, and a slot
 * whose deciding key already chose (or is locked), are a map like any
 * other: a nested map input whose properties are the child's keys. An
 * unresolved slot is the one shape the Tool API cannot say, and
 * fromSlot() says why and what is emitted instead.
 *
 * One Tool API gap is worth naming here rather than in a method
 * docblock, because it is why a derived tool declares its outputs as
 * the deriver writes them: outputs are declared statically on the
 * #[Tool] attribute, and there is no output_definition_refiners beside
 * input_definition_refiners, so a tool cannot say "this output is
 * whatever the subject turns out to describe" the way it can for an
 * input. A tool that wants it has to override getOutputDefinitions()
 * itself, which no declaration makes visible.
 *
 * @see \Drupal\data_surface\DataSurfaceInterface
 * @see \Drupal\tool\Normalizer\ContextDefinitionNormalizer
 */
final class SurfaceInputDefinitions {

  use StringTranslationTrait;

  /**
   * The constraint the Tool API's normalizer reads an enum from.
   */
  protected const CHOICE = 'Choice';

  /**
   * Constructs a SurfaceInputDefinitions converter.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, the one place a constraint is read as a list
   *   of allowed values.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which the notes and fallback
   *   labels this converter writes are built through.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Converts a whole surface into one map input definition.
   *
   * The surface's keys become the map's properties, in the order the
   * surface declares them, so a caller reading the advertised schema
   * reads the surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to convert.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label of the resulting map input.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description of the resulting map input.
   * @param bool $required
   *   Whether the resulting map input is required.
   * @param mixed $default_value
   *   The default for the map as a whole. Deliberately not assembled
   *   from the surface's own defaults: a map default is handed back
   *   whenever the caller sends nothing, and on an update path a full
   *   set of defaults arriving as if the caller had typed it would
   *   overwrite every stored value. The surface's defaults live on the
   *   converted properties, which is where they describe rather than
   *   act.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map input definition.
   */
  public function fromSurface(DataSurfaceInterface $surface, TranslatableMarkup|string $label, TranslatableMarkup|string $description, bool $required = FALSE, mixed $default_value = NULL): MapInputDefinition {
    $properties = [];
    $definitions = $surface->getDefinitions();
    foreach ($definitions->entries() as $name => $entry) {
      $property = $this->fromEntry($entry);
      if ($definitions->isLocked($name)) {
        // A locked surface key has exactly one legal value, and the Tool
        // API has the same idea for a whole input. It has no effect on a
        // map's property today, because only top level inputs are
        // filtered by it; carrying it anyway keeps the statement in the
        // definition for the day the normalizer reads it.
        $property->setLocked();
      }
      $properties[$name] = $property;
    }
    return new MapInputDefinition(
      label: $label,
      description: $description,
      required: $required,
      default_value: $default_value,
      property_definitions: $properties,
    );
  }

  /**
   * Converts one key of a surface, subsurfaces included.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input definition.
   */
  protected function fromEntry(SurfaceEntry $entry): InputDefinitionInterface {
    if ($entry->slot !== NULL && DefinitionMetadata::slotOf($entry->definition) !== NULL) {
      return $this->fromSlot($entry->slot, $entry->definition);
    }
    $child = $entry->attachment?->child;
    if ($child === NULL || !$entry->definition instanceof ComplexDataDefinitionInterface) {
      return $this->fromDefinition($entry->definition);
    }
    // An attached child is a map whose properties are the child's own
    // keys, converted the way the child's own entries say: a slot inside
    // the child is still a slot.
    $map = $this->fromDefinition($entry->definition);
    if ($map instanceof MapInputDefinition) {
      $properties = $entry->definition->getPropertyDefinitions();
      foreach ($child->getDefinitions()->entries() as $name => $child_entry) {
        $map->setPropertyDefinition((string) $name, $child_entry->slot !== NULL && isset($properties[$name]) && DefinitionMetadata::slotOf($properties[$name]) !== NULL
          ? $this->fromSlot($child_entry->slot, $properties[$name])
          : $this->fromDefinition($properties[$name] ?? $child_entry->definition));
      }
    }
    return $map;
  }

  /**
   * Converts a slot whose deciding key has chosen nothing.
   *
   * The widest honest schema, and a known Tool API gap. What a slot is
   * — "this map is exactly variant A's when the sibling is a, exactly
   * variant B's when it is b" — is a union keyed by a sibling. JSON
   * Schema can say it, but only on the parent: an `if` naming the
   * sibling's `const` and a `then` naming this property's schema, once
   * per variant, or a `oneOf` over whole parent objects. The Tool API's
   * definitions have no place for either. A context definition carries
   * one data type, constraints and, for a map, its property definitions;
   * MapInputDefinition has no union, no conditional and no `oneOf`, and
   * its normalizer emits one schema per property with no reference to a
   * sibling. And `oneOf` on the slot's own schema would not do: the
   * deciding key is not inside the slot's value, so nothing in that
   * value could select a branch.
   *
   * So the slot is advertised as a map holding every key any variant
   * declares, in variant order, none of them required and none with a
   * default (both depend on the variant), each annotated with the values
   * of the deciding key it belongs to, and the whole table said in the
   * slot's own description. Nothing is promised that the pipeline does
   * not keep: it refuses a key from another variant by name, and judges
   * the chosen variant's keys by that variant's own rules. A key two
   * variants share is advertised with the first variant's definition.
   *
   * @param \Drupal\data_surface\SurfaceSlot $slot
   *   The slot's variant table.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $placeholder
   *   The placeholder the slot advertises.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The union map.
   */
  public function fromSlot(SurfaceSlot $slot, DataDefinitionInterface $placeholder): MapInputDefinition {
    $owners = [];
    $first = [];
    $table = [];
    foreach ($slot->variants as $id => $variant) {
      $keys = [];
      foreach ($variant->child->getDefinitions() as $name => $definition) {
        $owners[$name][] = (string) $id;
        $first[$name] ??= $definition;
        $keys[] = $name;
      }
      $table[] = $this->t('@value: @keys', [
        '@value' => $id,
        '@keys' => $keys === [] ? $this->t('nothing') : implode(', ', $keys),
      ]);
    }
    $properties = [];
    foreach ($first as $name => $definition) {
      // A copy, said the way the union means it: optional, with no
      // default, and the variants it belongs to named. Only its own
      // flags change, so a shallow clone leaves the variant untouched.
      $union = clone $definition;
      $note = $this->t('Only when @by is @values.', [
        '@by' => $slot->by,
        '@values' => implode(', ', $owners[$name]),
      ]);
      $description = (string) $this->description($definition);
      if ($union instanceof DataDefinition) {
        $union->setRequired(FALSE);
        $union->setDescription($description === '' ? $note : $this->t('@description @note', [
          '@description' => $description,
          '@note' => $note,
        ]));
      }
      DefinitionMetadata::setDefaultValue($union, NULL);
      $properties[$name] = $this->fromDefinition($union);
    }
    $note = $this->t('Its keys depend on @by. @table.', [
      '@by' => $slot->by,
      '@table' => $table === [] ? $this->t('No variant fills it') : implode('; ', array_map('strval', $table)),
    ]);
    $description = (string) $this->description($placeholder);
    return new MapInputDefinition(
      label: $this->label($placeholder),
      description: $description === '' ? $note : $this->t('@description @note', [
        '@description' => $description,
        '@note' => $note,
      ]),
      required: $placeholder->isRequired(),
      property_definitions: $properties,
    );
  }

  /**
   * Converts a surface's outputs into the Tool API's output definitions.
   *
   * The same bridge in the other direction. A tool's outputs are the
   * Tool API's output definitions rather than its input definitions —
   * and rightly so: an output is never rendered as a form
   * element, never refined by a caller's other answers, and never
   * locked, so the three things an input definition adds are all
   * meaningless here.
   *
   * What travels: the data type, the label, the description, whether
   * the output is required, the constraints, and — through the same
   * enum treatment the inputs get — a plain Choice beside any
   * constraint that names a value list, so the advertised schema shows
   * the vocabulary.
   *
   * What is lost, beyond the two the inputs already lose (example
   * values and type settings, both Tool API gaps):
   * - The presence rule. A surface says an output is absent when the
   *   producer omitted it; JSON Schema says the same thing with
   *   `required`, which is what `required` converts to, so the
   *   statement survives — but the Omitted sentinel itself does not
   *   travel, because it is a PHP marker and not a value.
   * - Who contributed what. A mounted third-party output arrives as an
   *   ordinary property of the third_party_outputs map.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface whose outputs to convert.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The output definitions, keyed by output name, in the order the
   *   surface declares them. Empty for a surface that declares no
   *   outputs, which is what a tool with nothing to add should pass
   *   straight over.
   */
  public function outputsFromSurface(DataSurfaceInterface $surface): array {
    $outputs = [];
    foreach ($surface->getOutputDefinitions() as $name => $definition) {
      $outputs[$name] = $this->outputFromDefinition($definition);
    }
    return $outputs;
  }

  /**
   * Converts one core data definition into one output definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to convert.
   *
   * @return \Drupal\tool\TypedData\OutputDefinitionInterface
   *   The output definition.
   */
  public function outputFromDefinition(DataDefinitionInterface $definition): OutputDefinitionInterface {
    $label = $this->label($definition);
    $description = $this->description($definition);
    $constraints = $this->constraints($definition);
    $required = $definition->isRequired();

    if ($definition instanceof ListDataDefinitionInterface) {
      return new ListOutputDefinition(
        label: $label,
        required: $required,
        description: $description,
        constraints: $constraints,
        item_definition: $this->outputFromDefinition($definition->getItemDefinition()),
      );
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties !== []) {
      $property_definitions = [];
      foreach ($properties as $name => $property_definition) {
        $property_definitions[$name] = $this->outputFromDefinition($property_definition);
      }
      return new MapOutputDefinition(
        label: $label,
        required: $required,
        description: $description,
        constraints: $constraints,
        property_definitions: $property_definitions,
      );
    }
    // No default value argument anywhere above, and none here: an
    // output carries none, which is the one place the two directions of
    // this bridge differ in substance rather than in class.
    return new OutputDefinition(
      data_type: $definition->getDataType(),
      label: $label,
      required: $required,
      description: $description,
      constraints: $constraints,
    );
  }

  /**
   * Converts one core data definition into one input definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to convert.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input definition.
   */
  public function fromDefinition(DataDefinitionInterface $definition): InputDefinitionInterface {
    $label = $this->label($definition);
    $description = $this->description($definition);
    $constraints = $this->constraints($definition);
    $required = $definition->isRequired();
    $secret = DefinitionMetadata::isSecret($definition);
    // A secret carries no default across. A default is a value the
    // advertised schema shows to every caller, and a secret's whole
    // claim is that its value is never read back; a surface that
    // declares one anyway is saying something it cannot mean.
    $default = !$secret && DefinitionMetadata::hasDefaultValue($definition)
      ? DefinitionMetadata::getDefaultValue($definition)
      : NULL;
    if ($secret) {
      $description = $this->secretNote($description);
    }

    if ($definition instanceof ListDataDefinitionInterface) {
      return new ListInputDefinition(
        label: $label,
        description: $description,
        required: $required,
        default_value: $default,
        constraints: $constraints,
        item_definition: $this->fromDefinition($definition->getItemDefinition()),
      );
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties !== []) {
      $property_definitions = [];
      foreach ($properties as $name => $property_definition) {
        $property_definitions[$name] = $this->fromDefinition($property_definition);
      }
      return new MapInputDefinition(
        label: $label,
        description: $description,
        required: $required,
        default_value: $default,
        constraints: $constraints,
        property_definitions: $property_definitions,
      );
    }
    return new InputDefinition(
      data_type: $definition->getDataType(),
      label: $label,
      description: $description,
      required: $required,
      default_value: $default,
      constraints: $constraints,
    );
  }

  /**
   * Defaults each property to the value storage holds for it now.
   *
   * For a `values` input built in a context that changes a thing that
   * exists, so a caller reads what is there, not what a new one would
   * start from. A key storage holds nothing for keeps its declared
   * default. A secret keeps none: its stored value is never read back. A
   * subsurface is walked key by key through the child that describes the
   * stored value (a slot's, the variant its stored deciding key chooses,
   * and only when the stored value fits it), so a secret inside a child
   * is kept off the schema too, and the map itself carries no default:
   * fromSurface() says why a map's default would act rather than describe.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The converted surface, or a converted subsurface; changed in place.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface it was converted from.
   * @param array $stored
   *   What the surface's target loads, keyed by surface key.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withStored(MapInputDefinition $map, DataSurfaceInterface $surface, array $stored): MapInputDefinition {
    $properties = $map->getPropertyDefinitions();
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $property = $properties[$name] ?? NULL;
      if (!$property instanceof InputDefinitionInterface || !array_key_exists($name, $stored) || DefinitionMetadata::isSecret($entry->definition)) {
        continue;
      }
      if (!$entry->isNested()) {
        $property->setDefaultValue($stored[$name]);
        continue;
      }
      $child = $entry->childFor($stored);
      $chosen = $entry->slot?->chosen($stored[$entry->slot->by] ?? NULL);
      if ($child !== NULL && $property instanceof MapInputDefinition && is_array($stored[$name])
        && ($chosen === NULL || $entry->slot->fits($chosen, $stored[$name]))) {
        $this->withStored($property, $child, $stored[$name]);
      }
    }
    return $map;
  }

  /**
   * Says in words what phase B will say in the schema.
   *
   * JSON Schema has the keyword for this — a secret is `writeOnly`, and
   * the emitter the roadmap's phase B lands will say so — but the Tool
   * API's context definitions have nowhere to carry it today, so an
   * agent reading the advertised input would have no way to know that
   * sending this key replaces a value it can never read. A sentence in
   * the description is small, honest, and costs nothing to delete on the
   * day the keyword travels.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description so far, empty when the definition declares none.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description with the note appended.
   */
  protected function secretNote(TranslatableMarkup|string $description): TranslatableMarkup {
    $note = $this->t('Write only: the stored value is never returned, and sending nothing leaves it unchanged.');
    if ((string) $description === '') {
      return $note;
    }
    return $this->t('@description @note', [
      '@description' => $description,
      '@note' => $note,
    ]);
  }

  /**
   * Copies a definition's constraints, teaching the enum one to travel.
   *
   * The constraints are carried across by name, with the same options, so
   * a Drupal constraint plugin goes on validating exactly as it did on
   * the surface. The one
   * addition is the enum: the Tool API's schema normalizer reads Choice
   * and AllowedValues and nothing else, so a value list declared as
   * anything else — the labeled choice constraint, an existence
   * constraint such as Country or LanguageExists — would simply not
   * appear in the advertised schema. The list is asked for once, from
   * the options service, which is the only code that knows how to read a
   * constraint as a list; a plain Choice carrying those values is added
   * beside the constraint that named them. Both then validate the same
   * value space, so the duplication cannot make them disagree.
   *
   * LabeledChoice is a Symfony Choice subclass, so one might expect the
   * normalizer to see it without this. It does not: the normalizer
   * matches constraints by their Drupal plugin ID — isset($c['Choice'])
   * — and never with instanceof, so a subclass registered under its own
   * ID is invisible to it and the duplicate has to stay until that is a
   * Tool API change. FieldToolsComparisonTest is where removing it stops
   * emitting the enum.
   *
   * A definition that already carries a Choice keeps it: it is its own
   * enum, and the options service would only hand back what it says.
   *
   * Every option value is then made a scalar (scalarOptions() says how
   * and why), so a LabeledChoice's labels travel as the strings they
   * read as rather than as TranslatableMarkup.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return array<string, mixed>
   *   The constraints, keyed by constraint name.
   *
   * @throws \LogicException
   *   When an option holds an object that has no scalar spelling.
   */
  protected function constraints(DataDefinitionInterface $definition): array {
    $constraints = $definition->getConstraints();
    if (!isset($constraints[self::CHOICE])) {
      $set = $this->options->resolve($definition);
      if ($set !== NULL && $set->options !== []) {
        $constraints[self::CHOICE] = ['choices' => array_keys($set->options)];
      }
    }
    foreach ($constraints as $name => $options) {
      $constraints[$name] = $this->scalarOptions($options, (string) $name);
    }
    return $constraints;
  }

  /**
   * Spells a constraint's options with scalars and arrays alone.
   *
   * A tool definition's constraint options are config-schema shaped:
   * the Tool API copies them into the config schema it suggests for a
   * tool, and Tool Explorer prints that schema with Yaml::encode(), which
   * refuses an object. A surface's own constraints may hold objects — a
   * LabeledChoice's labels and descriptions are TranslatableMarkup —
   * because the surface is the PHP side and they validate the same
   * either way. So a stringable object converts to the string it reads
   * as, a backed enum to its value, and anything else is refused here,
   * by name, rather than as an encoding error on a page that has nothing
   * to do with it.
   *
   * What the string costs: a tool definition is cached once for every
   * language, so a label is in the language discovery ran in. It costs
   * nothing an invoker reads today, because the Tool API's normalizer
   * advertises a value list from Choice alone and never these labels;
   * the tool's own label and description stay TranslatableMarkup, which
   * the Tool API takes as such.
   *
   * @param mixed $options
   *   A constraint's options, or one value inside them.
   * @param string $path
   *   Where the value is, for the message.
   *
   * @return mixed
   *   The same options, with no object anywhere in them.
   *
   * @throws \LogicException
   *   When an object is neither stringable nor a backed enum.
   */
  protected function scalarOptions(mixed $options, string $path): mixed {
    if (is_array($options)) {
      foreach ($options as $key => $value) {
        $options[$key] = $this->scalarOptions($value, $path . '.' . $key);
      }
      return $options;
    }
    if (!is_object($options)) {
      return $options;
    }
    if ($options instanceof \BackedEnum) {
      return $options->value;
    }
    if ($options instanceof \Stringable) {
      return (string) $options;
    }
    throw new \LogicException(sprintf('The constraint option %s holds a %s, which a tool definition cannot carry: its constraint options are config schema, scalars and arrays only.', $path, get_class($options)));
  }

  /**
   * Gets a definition's label, which an input definition must have.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The label.
   */
  protected function label(DataDefinitionInterface $definition): TranslatableMarkup|string {
    $label = $definition->getLabel();
    if ($label === NULL || (string) $label === '') {
      return $this->t('@type value', ['@type' => $definition->getDataType()]);
    }
    return $label instanceof TranslatableMarkup ? $label : (string) $label;
  }

  /**
   * Gets a definition's description, or nothing when it declares none.
   *
   * An input definition demands a description where a data definition
   * treats it as optional, and the empty string is the honest answer: a
   * manufactured sentence repeating the label reads as help text a human
   * wrote, and tells a reader nothing the label did not.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The description, empty when the definition declares none.
   */
  protected function description(DataDefinitionInterface $definition): TranslatableMarkup|string {
    $description = $definition->getDescription();
    if ($description === NULL) {
      return '';
    }
    return $description instanceof TranslatableMarkup ? $description : (string) $description;
  }

}
