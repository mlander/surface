<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;

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
 * Resolving a definition's effective default — including assembling a
 * map's default from its properties — lives here too, beside the
 * metadata it reads, so the surface value object has no public static of
 * its own and a host answering a static defaults protocol reads the same
 * rule the surface does.
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
   * surface, because a host with a static defaults protocol has to
   * answer from the definitions alone, without a built surface: a field
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
   * - a settings shape decorated with EncryptedSettingsShape encrypts
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
