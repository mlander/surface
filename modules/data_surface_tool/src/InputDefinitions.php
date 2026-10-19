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
use Drupal\data_surface\Pipeline\ViolationMessage;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Slot;
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
 * The whole bridge. A data surface is an ordered set of
 * core data definitions; a tool input is a context definition. The two
 * describe the same thing in two vocabularies, so the conversion is a
 * rename plus the three places where the Tool API keeps its own class:
 * a map becomes a map input definition with property definitions, a list
 * becomes a list input definition with an item definition, and anything
 * else becomes a plain input definition carrying the data type.
 *
 * Nothing about any particular host, field type or setting appears here.
 * What a data surface says about a value (its type, its label, its help
 * text, whether it is required, what it starts from, and which values it
 * allows) is what the converted definition says, and the Tool API's own
 * normalizer turns that into the JSON Schema an invoker advertises.
 *
 * A key the data surface declared secret converts with no default value and
 * with a write-only note appended to its description; secretNote() says
 * why the note is prose rather than the JSON Schema keyword it will
 * become. A read leaves it out altogether: secrets() finds every one,
 * at any depth, and both the read's output definition and the values it
 * answers with are cut by that one answer.
 *
 * Two things a data surface knows survive the conversion only in part, and
 * both are Tool API gaps rather than choices made here:
 * - Example values. DefinitionMetadata carries them on a definition and
 *   JSON Schema has the keyword, but a context definition has nowhere to
 *   put them, so they are dropped.
 * - Type settings. Core's data definitions carry a settings array; a
 *   context definition does not, and rebuilds its data definition from
 *   the data type alone, so settings are dropped too.
 *
 * The data surface's outputs convert the same way, into the output
 * definitions the Tool API declares outputs with; outputsFromSurface()
 * says what that direction loses on top of these two.
 *
 * Children convert by what they are. An attached child, and a slot
 * whose deciding key already chose (or is locked), are a map like any
 * other: a nested map input whose properties are the child's keys. A
 * collection is a list input of those maps, its Count the list's
 * constraint. An unresolved slot is the one shape the Tool API cannot
 * say, and fromSlot() says why and what is emitted instead.
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
final class InputDefinitions {

  use StringTranslationTrait;

  /**
   * The constraint the Tool API's normalizer reads an enum from.
   */
  protected const CHOICE = 'Choice';

  /**
   * Constructs a InputDefinitions converter.
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
   * Converts a whole data surface into one map input definition.
   *
   * The data surface's keys become the map's properties, in the order
   * it declares them, so a caller reading the advertised schema reads
   * the data surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface to convert.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label of the resulting map input.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description of the resulting map input.
   * @param bool $required
   *   Whether the resulting map input is required.
   * @param mixed $default_value
   *   The default for the map as a whole. Deliberately not assembled
   *   from the data surface's own defaults: a map default is handed back
   *   whenever the caller sends nothing, and on an update path a full
   *   set of defaults arriving as if the caller had typed it would
   *   overwrite every stored value. The data surface's defaults live on the
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
      // A key a refiner may rule out is required only where it applies,
      // which a listing cannot say: optional here, and the pipeline asks
      // for it where it applies.
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      static::optionalWhereRuledOut($property, $entry->mayNotApply());
      if ($definitions->isLocked($name)) {
        // A locked key has exactly one legal value, and the Tool
        // API has the same idea for a whole input. It has no effect on a
        // map's property today, because only top level inputs are
        // filtered by it; carrying it anyway keeps the statement in the
        // definition for the day the normalizer reads it. A child its
        // access locked is a locked map, and every key inside it is
        // locked with it.
        static::lock($property);
      }
      if ($entry->runtimeAccess) {
        $this->runtimeAccessNote($property);
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
   * Makes an input optional wherever a refiner may rule it out.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted key; changed in place.
   * @param string[] $paths
   *   Where: the key itself as '', or a property's dotted path below it.
   */
  protected static function optionalWhereRuledOut(InputDefinitionInterface $input, array $paths): void {
    foreach ($paths as $path) {
      $target = $input;
      foreach ($path === '' ? [] : explode('.', $path) as $property) {
        $target = $target instanceof MapInputDefinition ? $target->getPropertyDefinition($property) : NULL;
      }
      if ($target instanceof InputDefinitionInterface) {
        $target->setRequired(FALSE);
      }
    }
  }

  /**
   * Marks an input locked, and every property inside it.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The input.
   */
  protected static function lock(InputDefinitionInterface $input): void {
    $input->setLocked();
    if ($input instanceof MapInputDefinition) {
      foreach ($input->getPropertyDefinitions() as $property) {
        if ($property instanceof InputDefinitionInterface) {
          static::lock($property);
        }
      }
    }
    if ($input instanceof ListInputDefinition) {
      // A locked collection locks every item with it.
      static::lock($input->getItemDefinition());
    }
  }

  /**
   * Converts one key of a data surface, children included.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input definition.
   */
  protected function fromEntry(Entry $entry): InputDefinitionInterface {
    if ($entry->slot !== NULL && DefinitionMetadata::slotOf($entry->definition) !== NULL) {
      return $this->fromSlot($entry->slot, $entry->definition);
    }
    $item = $entry->definition instanceof ListDataDefinitionInterface ? $entry->definition->getItemDefinition() : NULL;
    if ($entry->list !== NULL && $item instanceof ComplexDataDefinitionInterface) {
      // A collection is a list of the child's maps, its Count carried as
      // the list's constraint, each item converted the way the child's
      // own entries say.
      $list = $this->fromDefinition($entry->definition);
      if ($list instanceof ListInputDefinition) {
        $list->setItemDefinition($this->fromChild($entry->list->child(), $item));
      }
      return $list;
    }
    $child = $entry->attachment?->child;
    if ($child === NULL || !$entry->definition instanceof ComplexDataDefinitionInterface) {
      return $this->fromDefinition($entry->definition);
    }
    return $this->fromChild($child, $entry->definition);
  }

  /**
   * Converts a child at a key, or each item of a collection.
   *
   * A map whose properties are the child's own keys, converted the way
   * the child's own entries say: a slot inside the child is still a
   * slot, and a collection still a list.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child.
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $definition
   *   The map it is advertised as at the key.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The map input.
   */
  protected function fromChild(DataSurfaceInterface $child, ComplexDataDefinitionInterface $definition): InputDefinitionInterface {
    $map = $this->fromDefinition($definition);
    if ($map instanceof MapInputDefinition) {
      $properties = $definition->getPropertyDefinitions();
      foreach ($child->getDefinitions()->entries() as $name => $child_entry) {
        $property = $properties[$name] ?? $child_entry->definition;
        $converted = match (TRUE) {
          $child_entry->slot !== NULL && isset($properties[$name]) && DefinitionMetadata::slotOf($properties[$name]) !== NULL => $this->fromSlot($child_entry->slot, $properties[$name]),
          $child_entry->list !== NULL => $this->fromEntry($child_entry->withDefinition($property)),
          default => $this->fromDefinition($property),
        };
        // As at the top: a key the child's refiners may rule out, by the
        // child's own values or by a port, is optional in a listing.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        static::optionalWhereRuledOut($converted, $child_entry->mayNotApply());
        if ($child_entry->runtimeAccess) {
          $this->runtimeAccessNote($converted);
        }
        $map->setPropertyDefinition((string) $name, $converted);
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
   * @param \Drupal\data_surface\Slot $slot
   *   The slot's variant table.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $placeholder
   *   The placeholder the slot advertises.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The union map.
   */
  public function fromSlot(Slot $slot, DataDefinitionInterface $placeholder): MapInputDefinition {
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
   * Converts a data surface's outputs into Tool API output definitions.
   *
   * The same bridge in the other direction. A tool's outputs are the
   * Tool API's output definitions rather than its input definitions,
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
   * - The presence rule. A data surface says an output is absent when
   *   the producer omitted it; JSON Schema says the same thing with
   *   `required`, which is what `required` converts to, so the
   *   statement survives, but the Omitted sentinel itself does not
   *   travel, because it is a PHP marker and not a value.
   * - Who contributed what. A mounted third-party output arrives as an
   *   ordinary property of the third_party_outputs map.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface whose outputs to convert.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The output definitions, keyed by output name, in the order the
   *   data surface declares them. Empty for one that declares no
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
    $secret = DefinitionMetadata::isSecret($definition);
    // A secret is optional whatever it requires: left out or null, the
    // stored secret stands, and the pipeline refuses one never set.
    // Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
    $required = $definition->isRequired() && !$secret;
    // A secret carries no default across. A default is a value the
    // advertised schema shows to every caller, and a secret's whole
    // claim is that its value is never read back; a data surface that
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
   * child is walked key by key through the data surface that describes the
   * stored value (a slot's, the variant its stored deciding key chooses,
   * and only when the stored value fits it), so a secret inside a child
   * is kept off the schema too, and the map itself carries no default:
   * fromSurface() says why a map's default would act rather than describe.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The converted data surface, or a converted child; changed in place.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface it was converted from.
   * @param array $stored
   *   What the resource's storage loads, keyed by its keys.
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
   * Takes every default off a map's properties, at any depth.
   *
   * For the static listing of an operation that changes a thing that
   * exists: a key left out keeps what is stored, so a default there would
   * say what does not happen. A list's items keep theirs, since a new
   * item has nothing stored.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map, changed in place.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withoutDefaults(MapInputDefinition $map): MapInputDefinition {
    foreach ($map->getPropertyDefinitions() as $property) {
      if (!$property instanceof InputDefinitionInterface) {
        continue;
      }
      $property->setDefaultValue(NULL);
      if ($property instanceof MapInputDefinition) {
        $this->withoutDefaults($property);
      }
    }
    return $map;
  }

  /**
   * Finds every key a data surface marks secret, at any depth.
   *
   * A secret is a scalar key of its own (DefinitionMetadata::setSecret()
   * refuses anything else), but it can sit anywhere: on the data surface, in
   * a plain map such as a module's mounted settings, in an attached
   * child, or in any variant of a slot. A slot's secrets are every
   * variant's, whichever variant the values choose, so a key one variant
   * keeps secret is never returned under that slot, even when another
   * variant names a plain key the same.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, true|array>
   *   A tree keyed like the values: TRUE where a key is secret, an array
   *   where one below it is. Empty when nothing is.
   */
  public function secrets(DataSurfaceInterface $surface): array {
    $secrets = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $found = $this->secretsOf($entry->definition);
      $children = $entry->slot === NULL ? [] : array_map(static fn ($variant): DataSurfaceInterface => $variant->child, $entry->slot->variants);
      if ($entry->attachment !== NULL) {
        $children[] = $entry->attachment->child;
      }
      foreach ($children as $child) {
        $found = $this->mergeSecrets($found, $this->secrets($child));
      }
      if ($found !== []) {
        $secrets[(string) $name] = $found;
      }
    }
    return $secrets;
  }

  /**
   * Leaves every secret out of a set of values.
   *
   * @param array $values
   *   The values, keyed like the data surface.
   * @param array<string, true|array> $secrets
   *   What secrets() found.
   *
   * @return array
   *   The values, with no secret in them.
   */
  public function withoutSecrets(array $values, array $secrets): array {
    foreach ($secrets as $name => $secret) {
      if (!array_key_exists($name, $values)) {
        continue;
      }
      if ($secret === TRUE) {
        unset($values[$name]);
      }
      elseif (is_array($values[$name])) {
        $values[$name] = $this->withoutSecrets($values[$name], $secret);
      }
    }
    return $values;
  }

  /**
   * Says a converted input as an output: the same shape, no promises.
   *
   * For an answer that reports what is stored, in the shape a caller
   * sends it: each map, list and scalar keeps its data type, label and
   * description, and nests as it did. Nothing else crosses. A default is
   * an input's idea. A constraint would be a promise a stored value
   * cannot keep once it goes stale, and the Tool API holds a tool's
   * outputs to their definitions, so a stale value would fail the read
   * that reports it. And no property is required, because a key storage
   * holds nothing for is absent.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted input.
   * @param array<string, true|array> $leave_out
   *   The properties to leave out, as secrets() finds them.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   The output's label, or NULL for the input's.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $description
   *   The output's description, or NULL for the input's. Given with a
   *   label, the output is required: it is the answer itself.
   *
   * @return \Drupal\tool\TypedData\OutputDefinitionInterface
   *   The output definition.
   */
  public function outputFromInput(InputDefinitionInterface $input, array $leave_out = [], TranslatableMarkup|string|null $label = NULL, TranslatableMarkup|string|null $description = NULL): OutputDefinitionInterface {
    $required = $label !== NULL;
    $label ??= $input->getLabel() ?? '';
    $description ??= $input->getDescription() ?? '';
    if ($input instanceof MapInputDefinition) {
      $properties = [];
      foreach ($input->getPropertyDefinitions() as $name => $property) {
        $secret = $leave_out[$name] ?? [];
        if ($secret !== TRUE && $property instanceof InputDefinitionInterface) {
          $properties[$name] = $this->outputFromInput($property, $secret);
        }
      }
      return new MapOutputDefinition(
        label: $label,
        description: $description,
        required: $required,
        property_definitions: $properties,
      );
    }
    if ($input instanceof ListInputDefinition) {
      return new ListOutputDefinition(
        label: $label,
        description: $description,
        required: $required,
        item_definition: $this->outputFromInput($input->getItemDefinition()),
      );
    }
    return new OutputDefinition(
      data_type: $input->getDataType(),
      label: $label,
      description: $description,
      required: $required,
      multiple: $input->isMultiple(),
    );
  }

  /**
   * Finds the secrets inside one core data definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return true|array
   *   TRUE when it is secret, or a list whose items are; the secrets
   *   among a map's properties, keyed like them; empty when none.
   */
  protected function secretsOf(DataDefinitionInterface $definition): true|array {
    if (DefinitionMetadata::isSecret($definition)) {
      return TRUE;
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      return $this->secretsOf($definition->getItemDefinition()) === [] ? [] : TRUE;
    }
    $secrets = [];
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $found = $this->secretsOf($property);
        if ($found !== []) {
          $secrets[(string) $name] = $found;
        }
      }
    }
    return $secrets;
  }

  /**
   * Merges two trees secrets() builds; a secret anywhere wins.
   *
   * @param true|array $one
   *   One tree, or TRUE.
   * @param true|array $other
   *   The other.
   *
   * @return true|array
   *   Both.
   */
  protected function mergeSecrets(true|array $one, true|array $other): true|array {
    if ($one === TRUE || $other === TRUE) {
      return TRUE;
    }
    foreach ($other as $name => $secret) {
      $one[$name] = isset($one[$name]) ? $this->mergeSecrets($one[$name], $secret) : $secret;
    }
    return $one;
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
   * Says on a key that only the subject decides whether it may be changed.
   *
   * A key a runtime access answer may lock or leave out, which a listing
   * built for the account alone keeps open. The Tool API's normalizer
   * writes JSON Schema from these typed definitions and carries a
   * description, and no extension keyword, so the description says it:
   * the served contract's `x-surface.access: "runtime"`, in words.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted key; changed in place.
   */
  protected function runtimeAccessNote(InputDefinitionInterface $input): void {
    $note = $this->t('Whether this may be changed is decided once the thing it belongs to is known: a call may find it locked or left out.');
    $description = (string) $this->description($input);
    if ($input instanceof DataDefinition) {
      $input->setDescription($description === '' ? $note : $this->t('@description @note', [
        '@description' => $description,
        '@note' => $note,
      ]));
    }
  }

  /**
   * Copies a definition's constraints, teaching the enum one to travel.
   *
   * The constraints are carried across by name, with the same options, so
   * a Drupal constraint plugin goes on validating exactly as it did on
   * the data surface. The one
   * addition is the enum: the Tool API's schema normalizer reads Choice
   * and AllowedValues and nothing else, so a value list declared as
   * anything else (the labeled choice constraint, an existence
   * constraint such as Country or LanguageExists) would simply not
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
   * read as rather than as TranslatableMarkup. A message option written
   * with Symfony's `{{ max }}` is respelled `%max`, which is what core's
   * translator fills in, so the Tool API's own refusal shows the number
   * as the pipeline's does (ViolationMessage).
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
      foreach ($constraints[$name] as $option => $value) {
        if (is_string($value) && preg_match('/message$/i', (string) $option)) {
          $constraints[$name][$option] = ViolationMessage::drupalPlaceholders($value);
        }
      }
    }
    return $constraints;
  }

  /**
   * Spells a constraint's options with scalars and arrays alone.
   *
   * A tool definition's constraint options are config-schema shaped:
   * the Tool API copies them into the config schema it suggests for a
   * tool, and Tool Explorer prints that schema with Yaml::encode(), which
   * refuses an object. A data surface's own constraints may hold objects
   * (a LabeledChoice's labels and descriptions are TranslatableMarkup),
   * because the data surface is the PHP side and they validate the same
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
