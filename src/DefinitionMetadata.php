<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Resource\Arrangement;

/**
 * Reads and writes definition metadata core cannot express yet.
 *
 * Core's data definitions can describe a value's type, label, settings
 * and constraints, but not the value it starts from nor what a valid
 * value looks like, so two core issues propose adding default value and
 * example methods to DataDefinition (see PLAN.md). Until those land this
 * class stores the same information under the definition array keys the
 * issues propose, 'default_value' and 'examples', reached through the
 * ArrayAccess that core's DataDefinition already offers. Every method
 * first asks whether the definition carries the proposed core method and
 * delegates to it when it does, so on the day core lands the feature
 * this class turns into a pass-through and callers need no change.
 *
 * The third key, 'secret', is the module's own rather than a core
 * proposal, and is written the same way for the same reason: it belongs
 * on the definition, core has nowhere to put it yet, and the day it does
 * the accessors delegate. What it means and what reads it is on
 * setSecret().
 *
 * "Carries the proposed method" is a question about the signature and
 * not only about the name, because core's field definitions already use
 * one of these names for something else; supportsDefaultValue() says how
 * the two are told apart.
 *
 * Resolving a definition's effective default (including assembling a
 * map's default from its properties) lives here too, beside the
 * metadata it reads, so the data surface value object has no public
 * static of its own and a host answering a static defaults protocol
 * reads the same rule the data surface does.
 */
final class DefinitionMetadata {

  /**
   * The definition array key holding the default value.
   */
  protected const DEFAULT_VALUE_KEY = 'default_value';

  /**
   * The definition array key holding the example values.
   */
  protected const EXAMPLES_KEY = 'examples';

  /**
   * The definition array key marking a value as secret.
   */
  protected const SECRET_KEY = 'secret';

  /**
   * The definition array key marking the variants' placeholder.
   *
   * Holds the sibling key whose value chooses the variant. The
   * module's own, like 'secret': core has no union type, and this is the
   * smallest thing a definition can say about being one.
   */
  protected const VARIANTS_BY_KEY = 'surface_variants_by';

  /**
   * The definition array key marking a map that only groups other maps.
   */
  protected const GROUPING_KEY = 'surface_grouping';

  /**
   * The definition array key holding where a module's mount is drawn.
   */
  protected const PLACED_AFTER_KEY = 'surface_placed_after';

  /**
   * The definition array key naming the shape a key was declared with.
   *
   * Holds the shape's id and its definition as its alters left it, which
   * is what the key may only narrow and what its def in the served
   * contract is written from.
   */
  protected const SHAPE_KEY = 'surface_shape';

  /**
   * The definition array key holding the config schema type a key names.
   */
  protected const CONFIG_TYPE_KEY = 'surface_config_type';

  /**
   * The definition array key marking a key its storage always holds.
   *
   * Written by the build step, from what the storage says, never by an
   * author: whether a key may hold nothing is a fact of where it is
   * stored, which the resource does not know.
   */
  protected const ALWAYS_HELD_KEY = 'surface_always_held';

  /**
   * The definition array key holding what each entry from the site is.
   *
   * Written by the build step on the map addEach() or attachEach()
   * declared: the entries are the site's, so a reader that describes the
   * map whatever the site lists (its config schema) reads the entry here.
   */
  protected const EACH_KEY = 'surface_each';

  /**
   * The offset where a key's variants say the provider that lists them.
   */
  protected const VARIANTS_FROM_KEY = 'surface_variants_from';

  /**
   * The offset marking variants a schema read into a structure states.
   *
   * Written by the build step on the key of variants a structure read from
   * a schema document holds (a component in a slot, its props chosen by
   * its id): the list is the document's, so the site's when a provider
   * wrote it, and the budget for what the site decides counts it.
   */
  protected const READ_VARIANTS_KEY = 'surface_read_variants';

  /**
   * The offset marking a deciding key whose variants offer one value.
   *
   * Written by the build step on a deciding key that declares no list of
   * its own and whose variants offer a single value: the key is a fixed
   * value then, drawn as one and stated as `const`.
   */
  protected const ONE_VARIANT_KEY = 'surface_one_variant';

  /**
   * The offset marking a key its operation's context fixed to one value.
   *
   * Written by the build step where a context's Choice of one value that
   * says it fixes the key narrows it: drawn as that value and stated as
   * `const` wherever the key holds a value, as a deciding key whose
   * variants offer one is.
   */
  protected const FIXED_KEY = 'surface_fixed';

  /**
   * The definition array key holding the label the owner gave a key.
   *
   * Written when an alter rewords a key it does not own, so what
   * describes the owner's own storage (its config schema) reads the
   * owner's words, not those of whichever modules were on.
   */
  protected const OWN_LABEL_KEY = 'surface_own_label';

  /**
   * The definition array key holding how a key is arranged where drawn.
   *
   * Holds an Arrangement: its group, weight, placeholder, prefix, suffix
   * and help. The module's own, like 'secret': core's form display says
   * the same for fields, and has nowhere on a data definition to say it.
   */
  protected const ARRANGEMENT_KEY = 'surface_arrangement';

  /**
   * The definition array key holding what a computed key is computed from.
   *
   * Holds a Computed: the keys, the class computing it and when. Set by
   * BlueprintInterface::compute() alone.
   */
  protected const COMPUTED_KEY = 'surface_computed';

  /**
   * The definition array key holding what a key is resolved from at use.
   *
   * Holds a Resolve: the keys, by their stored paths, and the class
   * resolving it. Set by BlueprintAdditionsInterface::resolve() alone, and
   * read by Resources::resolved() alone: nothing emitted states it.
   */
  protected const RESOLVE_KEY = 'surface_resolve';

  /**
   * Declares the value a definition starts from.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to write to.
   * @param mixed $value
   *   The default value; NULL is a declared default, distinct from
   *   declaring none.
   */
  public static function setDefaultValue(DataDefinitionInterface $definition, mixed $value): void {
    if (method_exists($definition, 'setDefaultValue') && static::supportsDefaultValue($definition)) {
      $definition->setDefaultValue($value);
      return;
    }
    static::arrayAccess($definition)->offsetSet(static::DEFAULT_VALUE_KEY, $value);
  }

  /**
   * Returns whether a definition declares a default value.
   *
   * Answered by key existence rather than by whether the stored value is
   * empty, because NULL is a legitimate declared default and has to stay
   * distinguishable from "no default declared".
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when a default value is declared.
   */
  public static function hasDefaultValue(DataDefinitionInterface $definition): bool {
    if (static::supportsDefaultValue($definition) && method_exists($definition, 'hasDefaultValue')) {
      return (bool) $definition->hasDefaultValue();
    }
    return static::keyExists($definition, static::DEFAULT_VALUE_KEY);
  }

  /**
   * Gets the value a definition starts from.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return mixed
   *   The declared default, or NULL when none is declared.
   */
  public static function getDefaultValue(DataDefinitionInterface $definition): mixed {
    if (method_exists($definition, 'getDefaultValue') && static::supportsDefaultValue($definition)) {
      return $definition->getDefaultValue();
    }
    // Reading through ArrayAccess creates the key when it is missing, so
    // ask first and only then read.
    if (!static::keyExists($definition, static::DEFAULT_VALUE_KEY)) {
      return NULL;
    }
    return static::arrayAccess($definition)->offsetGet(static::DEFAULT_VALUE_KEY);
  }

  /**
   * Resolves one definition's default, recursing through properties.
   *
   * A complex definition's default is assembled from whatever its
   * property definitions declare, so a map populates at every depth.
   * Properties that declare nothing are left out of the assembled array,
   * and a complex definition whose properties all declare nothing and
   * which declares nothing itself has no default.
   *
   * A default declared on the map itself merges over that assembly
   * rather than replacing it: declaring one property's starting value on
   * the map is a statement about that property, not an instruction to
   * forget what its siblings declared. A declared default that is not an
   * array has nothing to merge and stands on its own.
   *
   * The rule lives beside the metadata it reads rather than on the
   * data surface, because a host with a static defaults protocol has to
   * answer from the definitions alone, without a built structure: a field
   * formatter's defaultSettings() cannot consult an instance, so it
   * reads the attribute's definitions and resolves each one here rather
   * than keeping a second copy of this rule.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to resolve.
   *
   * @return mixed
   *   The default value, or NULL when none is declared.
   */
  public static function defaultOf(DataDefinitionInterface $definition): mixed {
    $declared = static::hasDefaultValue($definition);
    if (!$definition instanceof ComplexDataDefinitionInterface) {
      return $declared ? static::getDefaultValue($definition) : NULL;
    }
    $values = [];
    foreach ($definition->getPropertyDefinitions() as $property_name => $property_definition) {
      $nested = static::defaultOf($property_definition);
      if ($nested !== NULL || static::hasDefaultValue($property_definition)) {
        $values[$property_name] = $nested;
      }
    }
    if (!$declared) {
      return $values === [] ? NULL : $values;
    }
    $own = static::getDefaultValue($definition);
    if (!is_array($own) || $values === []) {
      return $own;
    }
    return array_replace_recursive($values, $own);
  }

  /**
   * Declares example values for a definition.
   *
   * Plural to match the JSON Schema keyword: a definition whose value
   * space has more than one shape wants one example per shape.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to write to.
   * @param array $examples
   *   The example values.
   */
  public static function setExamples(DataDefinitionInterface $definition, array $examples): void {
    if (method_exists($definition, 'setExamples') && static::supportsExamples($definition)) {
      $definition->setExamples($examples);
      return;
    }
    static::arrayAccess($definition)->offsetSet(static::EXAMPLES_KEY, $examples);
  }

  /**
   * Gets the example values a definition declares.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return array
   *   The examples, empty when none are declared.
   */
  public static function getExamples(DataDefinitionInterface $definition): array {
    if (method_exists($definition, 'getExamples') && static::supportsExamples($definition)) {
      return $definition->getExamples();
    }
    $access = static::arrayAccess($definition);
    if (!$access->offsetExists(static::EXAMPLES_KEY)) {
      return [];
    }
    $examples = $access->offsetGet(static::EXAMPLES_KEY);
    return is_array($examples) ? $examples : [];
  }

  /**
   * Marks a definition's value as a secret, or clears the mark.
   *
   * A secret key is one whose stored value must never be read back to
   * the caller that writes it: an API token, a password, a signing key.
   * Three rules follow the flag and are applied in three places, so
   * declaring it once is all an author does:
   * - a generated form renders the key as a password element and places
   *   no current value anywhere in the render array;
   * - not-configured input keeps the stored value instead of clearing
   *   it, because a form that cannot echo a secret submits an empty box
   *   for an unchanged one (docs/semantics.md says how a caller clears
   *   one on purpose);
   * - a settings layout decorated with EncryptedStorageLayout encrypts
   *   the value on its way to storage.
   *
   * A secret holds one scalar value. A list or a complex definition is
   * refused here rather than half-supported downstream: the keep rule
   * and the codec both act on one value, and a map whose properties are
   * partly secret would be neither encrypted nor honestly advertised.
   * Declare the secret as its own top level key of its own.
   *
   * Locked and secret may coexist. Locking says input cannot move the
   * value; secret says the value is never read back. A key that is both
   * shows as a disabled password box holding nothing.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to write to.
   * @param bool $secret
   *   TRUE to mark the value secret, FALSE to clear the mark.
   *
   * @throws \InvalidArgumentException
   *   When a list or complex definition is marked secret.
   */
  public static function setSecret(DataDefinitionInterface $definition, bool $secret = TRUE): void {
    if ($secret && ($definition instanceof ListDataDefinitionInterface || $definition instanceof ComplexDataDefinitionInterface)) {
      throw new \InvalidArgumentException(sprintf(
        'A secret holds one scalar value, so the %s definition "%s" cannot be marked secret. Declare the secret as a key of its own.',
        $definition instanceof ListDataDefinitionInterface ? 'list' : 'complex',
        $definition->getDataType(),
      ));
    }
    if (method_exists($definition, 'setSecret') && static::supportsSecret($definition)) {
      $definition->setSecret($secret);
      return;
    }
    static::arrayAccess($definition)->offsetSet(static::SECRET_KEY, $secret);
  }

  /**
   * Returns whether a definition's value is a secret.
   *
   * Answered by the stored value rather than by key existence, unlike
   * the default value: there is nothing a declared FALSE could mean that
   * declaring nothing does not mean already.
   *
   * A definition that offers neither the proposed core method nor array
   * access to its definition array answers FALSE rather than raising.
   * This is a question every accepted value asks, and a definition that
   * cannot carry the key cannot have declared it; writing to such a
   * definition still refuses loudly, which is where a mistake shows.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when the value is a secret.
   */
  public static function isSecret(DataDefinitionInterface $definition): bool {
    if (method_exists($definition, 'isSecret') && static::supportsSecret($definition)) {
      return (bool) $definition->isSecret();
    }
    if (!$definition instanceof \ArrayAccess) {
      return FALSE;
    }
    return $definition->offsetExists(static::SECRET_KEY) && (bool) $definition->offsetGet(static::SECRET_KEY);
  }

  /**
   * Marks a definition as the placeholder of a key's variants.
   *
   * The key is typed `any` until the sibling that chooses its variant
   * holds a value, and `any` alone reads as "anything at all". The mark is what
   * lets a reader holding only the definition know it means "one of the
   * declared variants, chosen by that key" instead; the variant table is
   * on the key's Entry, which is where an emitter reads it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The placeholder.
   * @param string $by
   *   The sibling key whose value chooses the variant.
   *
   * @see \Drupal\surface\Variants
   */
  public static function setVariantsBy(DataDefinitionInterface $definition, string $by): void {
    static::arrayAccess($definition)->offsetSet(static::VARIANTS_BY_KEY, $by);
  }

  /**
   * Gets the key that chooses the variant for a variants' placeholder.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|null
   *   The deciding key, or NULL when the definition is no placeholder of
   *   variants nothing has chosen yet: a plain key, or a key whose
   *   variant is chosen.
   */
  public static function variantsBy(DataDefinitionInterface $definition): ?string {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::VARIANTS_BY_KEY)) {
      return NULL;
    }
    $by = $definition->offsetGet(static::VARIANTS_BY_KEY);
    return is_string($by) ? $by : NULL;
  }

  /**
   * Marks a map as one that only groups other maps.
   *
   * The map third-party settings are mounted in holds one map per
   * contributing module and nothing of its own, so a form draws each
   * module's map as its own fieldset and nothing around them: a heading
   * above the modules' headings says nothing a person can act on. Its
   * label and description stay on the definition for a reader with the
   * definition alone.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   */
  public static function setGrouping(DataDefinitionInterface $definition): void {
    static::arrayAccess($definition)->offsetSet(static::GROUPING_KEY, TRUE);
  }

  /**
   * Returns whether a map only groups other maps.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when it is drawn as no element of its own.
   */
  public static function isGrouping(DataDefinitionInterface $definition): bool {
    return $definition instanceof \ArrayAccess
      && $definition->offsetExists(static::GROUPING_KEY)
      && (bool) $definition->offsetGet(static::GROUPING_KEY);
  }

  /**
   * Places a module's mount after a key of the frame it is mounted in.
   *
   * A mount is the map one module's added keys sit in, inside the map
   * that only groups the mounts (setGrouping()). Its keys are stored and
   * posted there, and drawn there unless its alter says where else: the
   * key named here is a top-level key of the same frame, and a form or a
   * renderer draws the mount's fieldset directly after that key's
   * element. Nothing about its value moves: placement is where it is
   * drawn, never where it is stored.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The mount.
   * @param string $key
   *   The key of the enclosing frame to draw the mount after.
   */
  public static function setPlacedAfter(DataDefinitionInterface $definition, string $key): void {
    static::arrayAccess($definition)->offsetSet(static::PLACED_AFTER_KEY, $key);
  }

  /**
   * Gets the key a module's mount is drawn after, if its alter placed it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|null
   *   The key of the enclosing frame, or NULL when the mount is drawn
   *   where it is stored, inside the grouping map.
   */
  public static function getPlacedAfter(DataDefinitionInterface $definition): ?string {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::PLACED_AFTER_KEY)) {
      return NULL;
    }
    $key = $definition->offsetGet(static::PLACED_AFTER_KEY);
    return is_string($key) ? $key : NULL;
  }

  /**
   * Marks a definition as a key declared with a shape.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param string $id
   *   The shape's id.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $shape
   *   The shape's definition, as its alters left it: what the key narrows.
   *
   * @see docs/decisions.md#a-shape-is-a-named-definition
   */
  public static function setShape(DataDefinitionInterface $definition, string $id, DataDefinitionInterface $shape): void {
    static::arrayAccess($definition)->offsetSet(static::SHAPE_KEY, ['id' => $id, 'definition' => $shape]);
  }

  /**
   * Gets the id of the shape a key was declared with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|null
   *   The shape's id, or NULL for a key declared with a type.
   */
  public static function shapeOf(DataDefinitionInterface $definition): ?string {
    $shape = static::shape($definition);
    return $shape === NULL ? NULL : $shape['id'];
  }

  /**
   * Gets the definition of the shape a key was declared with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The shape's definition as its alters left it, or NULL for a key
   *   declared with a type.
   */
  public static function shapeDefinition(DataDefinitionInterface $definition): ?DataDefinitionInterface {
    $shape = static::shape($definition);
    return $shape === NULL ? NULL : $shape['definition'];
  }

  /**
   * Marks a key declared with a config schema data type, `machine_name`.
   *
   * The key is of the typed data type the config schema type extends, a
   * string, with the constraints the config schema type states as its
   * own; the mark keeps the name, for the form's element and for what the
   * key narrows.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param string $type
   *   The config schema type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $declared
   *   The type's own definition: what the key narrows.
   *
   * @see docs/decisions.md#a-machine-name-is-a-computed-identity
   */
  public static function setConfigType(DataDefinitionInterface $definition, string $type, DataDefinitionInterface $declared): void {
    static::arrayAccess($definition)->offsetSet(static::CONFIG_TYPE_KEY, ['type' => $type, 'definition' => $declared]);
  }

  /**
   * Gets the config schema data type a key was declared with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|null
   *   The type, `machine_name`, or NULL for a key declared otherwise.
   */
  public static function configTypeOf(DataDefinitionInterface $definition): ?string {
    $type = $definition instanceof \ArrayAccess && $definition->offsetExists(static::CONFIG_TYPE_KEY) ? $definition->offsetGet(static::CONFIG_TYPE_KEY) : NULL;
    return is_array($type) && is_string($type['type'] ?? NULL) ? $type['type'] : NULL;
  }

  /**
   * Gets the definition of the config schema data type a key was declared with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The type's own definition, or NULL for a key declared otherwise.
   */
  public static function configTypeDefinition(DataDefinitionInterface $definition): ?DataDefinitionInterface {
    $type = $definition instanceof \ArrayAccess && $definition->offsetExists(static::CONFIG_TYPE_KEY) ? $definition->offsetGet(static::CONFIG_TYPE_KEY) : NULL;
    return is_array($type) && ($type['definition'] ?? NULL) instanceof DataDefinitionInterface ? $type['definition'] : NULL;
  }

  /**
   * Marks a deciding key whose variants offer one value.
   *
   * Its list is the variants' values, and there is one: nothing to
   * choose, so a form draws the value fixed, with its label, and both
   * documents state it as `const`. Only a list the variants derive is
   * marked; a list the key declares is the author's, and is drawn as
   * declared.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The deciding key.
   *
   * @see docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter
   */
  public static function setOneVariant(DataDefinitionInterface $definition): void {
    static::arrayAccess($definition)->offsetSet(static::ONE_VARIANT_KEY, TRUE);
  }

  /**
   * Marks a key its operation's context fixed to one value.
   *
   * A list the key declares is the author's, drawn as declared; a
   * context that fixes the key to a single value says the key is that
   * value where it is asked for, so a form draws the value fixed, with its
   * label, and both documents state it as `const`.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @see docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for
   */
  public static function setFixed(DataDefinitionInterface $definition): void {
    static::arrayAccess($definition)->offsetSet(static::FIXED_KEY, TRUE);
  }

  /**
   * Returns whether a key's context narrowed it to one value.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when a context fixed it to one value.
   */
  public static function isFixed(DataDefinitionInterface $definition): bool {
    return $definition instanceof \ArrayAccess
      && $definition->offsetExists(static::FIXED_KEY)
      && (bool) $definition->offsetGet(static::FIXED_KEY);
  }

  /**
   * Returns whether a deciding key's variants offer one value.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when its list is the variants' and holds one value.
   */
  public static function isOneVariant(DataDefinitionInterface $definition): bool {
    return $definition instanceof \ArrayAccess
      && $definition->offsetExists(static::ONE_VARIANT_KEY)
      && (bool) $definition->offsetGet(static::ONE_VARIANT_KEY);
  }

  /**
   * Marks a key its storage always holds a value for.
   *
   * Null on an optional key clears it, where clearing is possible. Where
   * the storage cannot hold nothing for the key (a config object whose
   * schema is fully validatable and does not say the key is nullable),
   * null is no answer the key takes: the pipeline refuses it naming the
   * default, both schemas leave null out of its type, and a select or
   * radios offers no empty choice once a valid one is chosen.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @see docs/decisions.md#a-key-its-storage-always-holds
   */
  public static function setAlwaysHeld(DataDefinitionInterface $definition): void {
    static::arrayAccess($definition)->offsetSet(static::ALWAYS_HELD_KEY, TRUE);
  }

  /**
   * Returns whether a key's storage always holds a value for it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when null cannot clear it; FALSE when it may be cleared, or
   *   when its storage says nothing.
   */
  public static function isAlwaysHeld(DataDefinitionInterface $definition): bool {
    return $definition instanceof \ArrayAccess
      && $definition->offsetExists(static::ALWAYS_HELD_KEY)
      && (bool) $definition->offsetGet(static::ALWAYS_HELD_KEY);
  }

  /**
   * Says a key is computed from others, and how.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param \Drupal\surface\Computed $computed
   *   What it is computed from, by what, and when.
   *
   * @see docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources
   */
  public static function setComputed(DataDefinitionInterface $definition, Computed $computed): void {
    static::arrayAccess($definition)->offsetSet(static::COMPUTED_KEY, $computed);
  }

  /**
   * Reads what a key is computed from.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return \Drupal\surface\Computed|null
   *   What it is computed from, or NULL for a key a caller types.
   */
  public static function computedOf(DataDefinitionInterface $definition): ?Computed {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::COMPUTED_KEY)) {
      return NULL;
    }
    $computed = $definition->offsetGet(static::COMPUTED_KEY);
    return $computed instanceof Computed ? $computed : NULL;
  }

  /**
   * Says a key's value is resolved at use from others, and how.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param \Drupal\surface\Resolve $resolve
   *   What it is resolved from, and by what.
   *
   * @see docs/decisions.md#a-key-may-stand-for-another-at-use
   */
  public static function setResolve(DataDefinitionInterface $definition, Resolve $resolve): void {
    static::arrayAccess($definition)->offsetSet(static::RESOLVE_KEY, $resolve);
  }

  /**
   * Reads what a key's value is resolved from at use.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return \Drupal\surface\Resolve|null
   *   What it is resolved from, or NULL for a key read as stored.
   */
  public static function resolveOf(DataDefinitionInterface $definition): ?Resolve {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::RESOLVE_KEY)) {
      return NULL;
    }
    $resolve = $definition->offsetGet(static::RESOLVE_KEY);
    return $resolve instanceof Resolve ? $resolve : NULL;
  }

  /**
   * Says what each entry of a map of keys from the site is.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map addEach() or attachEach() declared.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|string $each
   *   The definition every entry has, for addEach(); the structure every
   *   entry is, by class, for attachEach().
   *
   * @see docs/decisions.md#keys-that-come-from-the-site
   */
  public static function setEach(DataDefinitionInterface $definition, DataDefinitionInterface|string $each): void {
    static::arrayAccess($definition)->offsetSet(static::EACH_KEY, $each);
  }

  /**
   * Reads what each entry of a map of keys from the site is.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|string|null
   *   The definition every entry has, or the structure every entry is,
   *   by class; NULL for any other key.
   */
  public static function eachOf(DataDefinitionInterface $definition): DataDefinitionInterface|string|null {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::EACH_KEY)) {
      return NULL;
    }
    $each = $definition->offsetGet(static::EACH_KEY);
    return $each instanceof DataDefinitionInterface || is_string($each) ? $each : NULL;
  }

  /**
   * Says that a key's variants come from the site, and from which provider.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map attachVariants() declared.
   * @param string $from
   *   The provider's class.
   *
   * @see docs/decisions.md#a-schema-can-be-read-back-into-a-structure
   */
  public static function setVariantsFrom(DataDefinitionInterface $definition, string $from): void {
    static::arrayAccess($definition)->offsetSet(static::VARIANTS_FROM_KEY, $from);
  }

  /**
   * Reads which provider lists a key's variants from the site.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|null
   *   The provider's class; NULL for any other key, its variants classes
   *   alone or none.
   */
  public static function variantsFrom(DataDefinitionInterface $definition): ?string {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::VARIANTS_FROM_KEY)) {
      return NULL;
    }
    $from = $definition->offsetGet(static::VARIANTS_FROM_KEY);
    return is_string($from) ? $from : NULL;
  }

  /**
   * Says that a key's variants were read from a schema document.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map the variants are attached at.
   *
   * @see docs/decisions.md#a-budget-for-what-the-site-decides
   */
  public static function setReadVariants(DataDefinitionInterface $definition): void {
    static::arrayAccess($definition)->offsetSet(static::READ_VARIANTS_KEY, TRUE);
  }

  /**
   * Answers whether a key's variants are a list the site decides.
   *
   * Listed by a provider of variants from the site, or read from a schema
   * document, which a provider wrote where it nests (a component in a
   * slot).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map the variants are attached at.
   *
   * @return bool
   *   TRUE when the site's list decides which variants there are.
   *
   * @see docs/decisions.md#a-budget-for-what-the-site-decides
   */
  public static function variantsListedBySite(DataDefinitionInterface $definition): bool {
    return static::variantsFrom($definition) !== NULL
      || ($definition instanceof \ArrayAccess && $definition->offsetExists(static::READ_VARIANTS_KEY));
  }

  /**
   * Keeps the label a key's owner gave it, before another module rewords it.
   *
   * The first call wins: an owner's label is kept once, whichever alter
   * rewords it after.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   */
  public static function keepOwnLabel(DataDefinitionInterface $definition): void {
    $access = static::arrayAccess($definition);
    if (!$access->offsetExists(static::OWN_LABEL_KEY)) {
      $access->offsetSet(static::OWN_LABEL_KEY, $definition->getLabel());
    }
  }

  /**
   * Reads the label a key's owner gave it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return string|\Stringable|null
   *   The owner's label, which another module has since reworded; the
   *   label it has, when none has.
   */
  public static function ownLabel(DataDefinitionInterface $definition): string|\Stringable|null {
    if ($definition instanceof \ArrayAccess && $definition->offsetExists(static::OWN_LABEL_KEY)) {
      $label = $definition->offsetGet(static::OWN_LABEL_KEY);
      return is_string($label) || $label instanceof \Stringable ? $label : NULL;
    }
    return $definition->getLabel();
  }

  /**
   * Declares how a key is arranged where it is drawn.
   *
   * Replaces whatever arrangement the key had. The engine's seam: an
   * owner or an alter arranges a key with the blueprint's describe(),
   * which lays what it is given over what the key has. Read by the
   * generated form for every host, and stated by the served contract as
   * `x-surface.arrangement` when a renderer asks for widget hints. Nothing
   * in it changes what is accepted.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param \Drupal\surface\Resource\Arrangement $arrangement
   *   Its arrangement.
   *
   * @see docs/decisions.md#arrangement-is-declared-once
   */
  public static function setArrangement(DataDefinitionInterface $definition, Arrangement $arrangement): void {
    static::arrayAccess($definition)->offsetSet(static::ARRANGEMENT_KEY, $arrangement);
  }

  /**
   * Reads how a key is arranged where it is drawn.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   Its arrangement, or NULL when it declares none.
   */
  public static function arrangementOf(DataDefinitionInterface $definition): ?Arrangement {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::ARRANGEMENT_KEY)) {
      return NULL;
    }
    $arrangement = $definition->offsetGet(static::ARRANGEMENT_KEY);
    return $arrangement instanceof Arrangement ? $arrangement : NULL;
  }

  /**
   * Returns whether a key holds a value whatever is sent.
   *
   * A required key, or one its storage always holds: either way null is
   * no answer it takes, and a choice of it needs no empty option once a
   * valid one is chosen.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return bool
   *   TRUE when null cannot clear it.
   */
  public static function holdsValue(DataDefinitionInterface $definition): bool {
    return $definition->isRequired() || static::isAlwaysHeld($definition);
  }

  /**
   * Reads the shape a definition carries.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array{id: string, definition: \Drupal\Core\TypedData\DataDefinitionInterface}|null
   *   The shape, or NULL.
   */
  protected static function shape(DataDefinitionInterface $definition): ?array {
    if (!$definition instanceof \ArrayAccess || !$definition->offsetExists(static::SHAPE_KEY)) {
      return NULL;
    }
    $shape = $definition->offsetGet(static::SHAPE_KEY);
    return is_array($shape) && is_string($shape['id'] ?? NULL) && ($shape['definition'] ?? NULL) instanceof DataDefinitionInterface ? $shape : NULL;
  }

  /**
   * Returns whether a definition natively carries a default value.
   *
   * The getter is the arbiter, and it is checked by arity rather than by
   * name alone. Core's field definitions already answer to
   * getDefaultValue(), and they mean something else by it: a field's
   * default is per entity, so the method takes the entity as a required
   * argument and stores the value as a list of item arrays. A name test
   * cannot tell the two apart, and getting it wrong is not a quiet
   * mistake — the setter succeeds against the field's own shape and the
   * next read raises an error about the missing argument.
   *
   * So a required parameter means this is the field definition's method,
   * not the one the core issues propose, and the whole trio falls back
   * to the definition array. The setter follows the getter deliberately:
   * a definition whose default cannot be read natively must not be
   * written natively either, or the two would store in different places.
   *
   * One consequence worth knowing on a field definition: the fallback
   * writes the plain value at the 'default_value' key the Field API also
   * uses for its own literal, so a field definition's default belongs to
   * one API or the other and not to both at once.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to inspect.
   *
   * @return bool
   *   TRUE when the definition offers the proposed core methods.
   */
  protected static function supportsDefaultValue(DataDefinitionInterface $definition): bool {
    return static::isNativeAccessor($definition, 'getDefaultValue');
  }

  /**
   * Returns whether a definition natively carries example values.
   *
   * The same rule as for default values, and for the same reason: the
   * getter decides, it has to take no arguments to be the proposed one,
   * and the setter follows it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to inspect.
   *
   * @return bool
   *   TRUE when the definition offers the proposed core methods.
   */
  protected static function supportsExamples(DataDefinitionInterface $definition): bool {
    return static::isNativeAccessor($definition, 'getExamples');
  }

  /**
   * Returns whether a definition natively carries the secret flag.
   *
   * The same rule as for default values and examples, and for the same
   * reason: the getter decides, it has to take no arguments to be the
   * proposed one, and the setter follows it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to inspect.
   *
   * @return bool
   *   TRUE when the definition offers the proposed core methods.
   */
  protected static function supportsSecret(DataDefinitionInterface $definition): bool {
    return static::isNativeAccessor($definition, 'isSecret');
  }

  /**
   * Returns whether a getter exists and reads from the definition alone.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to inspect.
   * @param string $method
   *   The getter's name.
   *
   * @return bool
   *   TRUE when the method exists and needs no arguments.
   */
  protected static function isNativeAccessor(DataDefinitionInterface $definition, string $method): bool {
    if (!method_exists($definition, $method)) {
      return FALSE;
    }
    return (new \ReflectionMethod($definition, $method))->getNumberOfRequiredParameters() === 0;
  }

  /**
   * Returns whether a definition array key is present.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   * @param string $key
   *   The definition array key.
   *
   * @return bool
   *   TRUE when the key is present, whatever its value.
   */
  protected static function keyExists(DataDefinitionInterface $definition, string $key): bool {
    $access = static::arrayAccess($definition);
    // ArrayAccess::offsetExists() answers with isset() semantics on
    // core's DataDefinition, which cannot tell a stored NULL from a
    // missing key; toArray() gives the exact answer where it is offered.
    if (method_exists($definition, 'toArray')) {
      return array_key_exists($key, $definition->toArray());
    }
    return $access->offsetExists($key);
  }

  /**
   * Returns the definition as array access, or fails with a clear reason.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to reach into.
   *
   * @return \ArrayAccess
   *   The same definition, typed for array access.
   */
  protected static function arrayAccess(DataDefinitionInterface $definition): \ArrayAccess {
    if (!$definition instanceof \ArrayAccess) {
      throw new \LogicException(sprintf(
        'Cannot store default values or examples on %s: the definition neither offers the core methods nor array access to its definition array.',
        get_class($definition),
      ));
    }
    return $definition;
  }

}
