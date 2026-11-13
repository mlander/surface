<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\NodeMessage;
use Drupal\surface\DefinitionMetadata;
use PortableSchema\Builder\Schema;

/**
 * Says where two definitions, or two nodes, are not equal.
 *
 * The round trip's equality (DefinitionRoundTripTest), at every depth:
 * - the same PHP class;
 * - toArray() equal as a map: the same keys, and the same value for each
 *   key by the rules below. The order of the keys is not compared, and a
 *   `type` key that holds what getDataType() returns counts the same as
 *   no `type` key: the order of a definition array's keys and an
 *   unwritten `type` key are no facts. Only the definition's own array is
 *   compared as a map; an array inside a value (settings, a constraint's
 *   options, examples, a default) is compared in order;
 * - getDataType(), getClass(), isRequired(), isReadOnly(), isComputed(),
 *   getSettings() and getConstraints() equal;
 * - a map's property definitions equal by name, order and this rule, its
 *   main property name equal, a list's item definition equal by this rule;
 * - every public static getter of DefinitionMetadata (one that takes a
 *   definition alone) gives an equal answer, or both throw alike;
 * - the derived definition is a new object, at every depth the comparator
 *   reaches a definition.
 * Values are equal by these rules: a scalar and an array by `===` (an
 * array key by key, in order); a definition by the rule above; when
 * identity is asked, any other object only as the same object (a text,
 * a Computed, a Resolve, an Arrangement), but a NodeMessage, which the
 * read makes anew on each read, by its class and properties; when it is
 * not (after serialization), any object by its class and every property
 * by these rules, a text's rendering and translation service left out. A
 * getter compared by `===` when identity is asked, by these rules when
 * not. Two nodes are equal when their keywords are, in order, by these
 * rules, a node inside one by this rule, and both are boolean or required
 * in their parent alike.
 *
 * What it does not compare: the typed data manager a definition holds (a
 * typed config definition holds the typed config manager; a derived one
 * asks core's), and what a class keeps outside its definition array. So
 * it is used for the three classes the inverse derives (DataDefinition,
 * ListDataDefinition, MapDataDefinition): every other class is refused by
 * NodeDefinition::of() before it is compared. A definition held under a
 * setting or a key the module has no name for is, by the inverse's rule,
 * the same object on both sides, which the comparator reports as the
 * object read; no walked definition holds one.
 */
trait DefinitionEqualityTrait {

  /**
   * Lists where two definitions differ.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $a
   *   The definition read.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $b
   *   The definition derived.
   * @param string $at
   *   Where, for the message.
   * @param bool $identity
   *   TRUE to compare objects by identity; FALSE by class and content.
   *
   * @return list<string>
   *   Each difference; empty when equal.
   */
  protected static function definitionDifferences(DataDefinitionInterface $a, DataDefinitionInterface $b, string $at, bool $identity = TRUE): array {
    $differ = [];
    if ($a === $b) {
      return ["$at: the derived definition is the object read"];
    }
    if (get_class($a) !== get_class($b)) {
      return ["$at: class " . get_class($a) . ' against ' . get_class($b)];
    }
    if ($a instanceof DataDefinition && $b instanceof DataDefinition) {
      $left = static::own($a);
      $right = static::own($b);
      $keys = [array_keys($left), array_keys($right)];
      sort($keys[0], SORT_STRING);
      sort($keys[1], SORT_STRING);
      if ($keys[0] !== $keys[1]) {
        $differ[] = "$at: toArray() keys " . json_encode($keys[0]) . ' against ' . json_encode($keys[1]);
      }
      foreach ($left as $key => $value) {
        if (array_key_exists($key, $right)) {
          array_push($differ, ...static::valueDifferences($value, $right[$key], "$at toArray()[$key]", $identity));
        }
      }
    }
    $getters = [
      'getDataType' => static fn (DataDefinitionInterface $d): mixed => $d->getDataType(),
      'getClass' => static fn (DataDefinitionInterface $d): mixed => $d->getClass(),
      'isRequired' => static fn (DataDefinitionInterface $d): mixed => $d->isRequired(),
      'isReadOnly' => static fn (DataDefinitionInterface $d): mixed => $d->isReadOnly(),
      'isComputed' => static fn (DataDefinitionInterface $d): mixed => $d->isComputed(),
      'getSettings' => static fn (DataDefinitionInterface $d): mixed => $d->getSettings(),
      'getConstraints' => static fn (DataDefinitionInterface $d): mixed => $d->getConstraints(),
    ];
    foreach ($getters as $name => $getter) {
      [$left, $right] = [static::answer($getter, $a), static::answer($getter, $b)];
      if ($identity && $left !== $right) {
        $differ[] = "$at: $name() " . static::shown($left) . ' against ' . static::shown($right);
      }
      elseif (!$identity) {
        array_push($differ, ...static::valueDifferences($left, $right, "$at $name()", FALSE));
      }
    }
    if ($a instanceof ListDataDefinitionInterface && $b instanceof ListDataDefinitionInterface) {
      $items = [static::itemOf($a), static::itemOf($b)];
      if ($items[0] instanceof DataDefinitionInterface && $items[1] instanceof DataDefinitionInterface) {
        array_push($differ, ...static::definitionDifferences($items[0], $items[1], "$at/items", $identity));
      }
      elseif ($items[0] !== NULL || $items[1] !== NULL) {
        $differ[] = "$at/items: one side has no item definition";
      }
    }
    elseif ($a instanceof ComplexDataDefinitionInterface && $b instanceof ComplexDataDefinitionInterface) {
      $left = $a->getPropertyDefinitions();
      $right = $b->getPropertyDefinitions();
      if (array_keys($left) !== array_keys($right)) {
        $differ[] = "$at: properties " . json_encode(array_keys($left)) . ' against ' . json_encode(array_keys($right));
      }
      foreach ($left as $name => $property) {
        if (isset($right[$name])) {
          array_push($differ, ...static::definitionDifferences($property, $right[$name], "$at/properties/$name", $identity));
        }
      }
      if ($a->getMainPropertyName() !== $b->getMainPropertyName()) {
        $differ[] = "$at: getMainPropertyName()";
      }
    }
    foreach (static::metadataGetters() as $method) {
      $call = static fn (DataDefinitionInterface $d): mixed => $method->invoke(NULL, $d);
      array_push($differ, ...static::valueDifferences(static::answer($call, $a), static::answer($call, $b), "$at DefinitionMetadata::{$method->getName()}()", $identity));
    }
    return $differ;
  }

  /**
   * Lists where two values a definition holds differ.
   *
   * @param mixed $a
   *   The value read.
   * @param mixed $b
   *   The value derived.
   * @param string $at
   *   Where, for the message.
   * @param bool $identity
   *   TRUE to compare objects by identity.
   *
   * @return list<string>
   *   Each difference.
   */
  protected static function valueDifferences(mixed $a, mixed $b, string $at, bool $identity): array {
    if ($a instanceof DataDefinitionInterface || $b instanceof DataDefinitionInterface) {
      return $a instanceof DataDefinitionInterface && $b instanceof DataDefinitionInterface ? static::definitionDifferences($a, $b, $at, $identity) : ["$at: one side is a definition"];
    }
    if ($a instanceof Schema || $b instanceof Schema) {
      return $a instanceof Schema && $b instanceof Schema ? static::nodeDifferences($a, $b, $at, $identity) : ["$at: one side is a node"];
    }
    if (is_array($a) || is_array($b)) {
      if (!is_array($a) || !is_array($b) || array_keys($a) !== array_keys($b)) {
        return ["$at: " . static::shown($a) . ' against ' . static::shown($b)];
      }
      $differ = [];
      foreach ($a as $key => $value) {
        array_push($differ, ...static::valueDifferences($value, $b[$key], "$at.$key", $identity));
      }
      return $differ;
    }
    if (is_object($a) || is_object($b)) {
      if ($a === $b) {
        return [];
      }
      if (!is_object($a) || !is_object($b) || get_class($a) !== get_class($b) || $a instanceof \Closure) {
        return ["$at: " . static::shown($a) . ' against ' . static::shown($b)];
      }
      // A message the read writes itself is made anew on each read; every
      // other object is the one the author's definition holds.
      if ($identity && !$a instanceof NodeMessage) {
        return [$a instanceof MarkupInterface ? "$at: a text that is not the author's object" : "$at: " . get_class($a) . " equal in content but not the author's object"];
      }
      // An object met again below itself is compared once.
      static $pairs = [];
      $pair = spl_object_id($a) . ':' . spl_object_id($b);
      if (isset($pairs[$pair])) {
        return [];
      }
      $pairs[$pair] = TRUE;
      try {
        return static::valueDifferences(static::properties($a), static::properties($b), $at, $identity);
      }
      finally {
        unset($pairs[$pair]);
      }
    }
    return $a === $b ? [] : ["$at: " . static::shown($a) . ' against ' . static::shown($b)];
  }

  /**
   * Lists where two nodes differ.
   *
   * @param \PortableSchema\Builder\Schema $a
   *   The node read from the definition.
   * @param \PortableSchema\Builder\Schema $b
   *   The node read from the definition derived from it.
   * @param string $at
   *   Where, for the message.
   * @param bool $identity
   *   TRUE to compare objects by identity.
   *
   * @return list<string>
   *   Each difference.
   */
  protected static function nodeDifferences(Schema $a, Schema $b, string $at, bool $identity = TRUE): array {
    if ($a->isBoolean() !== $b->isBoolean() || $a->isRequired() !== $b->isRequired()) {
      return ["$at: boolean or required in its parent"];
    }
    if ($a->isBoolean()) {
      return $a->toDocument() === $b->toDocument() ? [] : ["$at: another boolean schema"];
    }
    return static::valueDifferences($a->all(), $b->all(), "$at node", $identity);
  }

  /**
   * Reads an object's properties, but the rendering a text keeps.
   *
   * @param object $object
   *   The object.
   *
   * @return array<string, mixed>
   *   Its properties by name, private and protected ones included; for a
   *   text, without the rendering it keeps and its translation service,
   *   which serialization drops.
   */
  protected static function properties(object $object): array {
    $properties = [];
    foreach ((array) $object as $name => $value) {
      $name = (string) preg_replace('/^\0.+\0/', '', (string) $name);
      if ($object instanceof MarkupInterface && in_array($name, ['translatedMarkup', 'stringTranslation'], TRUE)) {
        continue;
      }
      $properties[$name] = $value;
    }
    return $properties;
  }

  /**
   * Reads a definition's own array, as the comparator compares it.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   *
   * @return array
   *   Its toArray(), without a `type` key that holds what getDataType()
   *   returns.
   */
  protected static function own(DataDefinition $definition): array {
    $own = $definition->toArray();
    if (array_key_exists('type', $own) && $own['type'] === $definition->getDataType()) {
      unset($own['type']);
    }
    return $own;
  }

  /**
   * Reads a list's item definition, which core allows to be none.
   *
   * @param \Drupal\Core\TypedData\ListDataDefinitionInterface $list
   *   The list.
   *
   * @return mixed
   *   Its item definition, or NULL.
   */
  protected static function itemOf(ListDataDefinitionInterface $list): mixed {
    return $list->getItemDefinition();
  }

  /**
   * Lists DefinitionMetadata's public static getters.
   *
   * Every public static method that takes one argument, a definition, and
   * does not write (its name starts with neither `set` nor `keep`), found
   * by reflection, so a getter added later is compared without an edit.
   *
   * @return list<\ReflectionMethod>
   *   The getters.
   */
  protected static function metadataGetters(): array {
    $getters = [];
    foreach ((new \ReflectionClass(DefinitionMetadata::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
      $parameters = $method->getParameters();
      $type = isset($parameters[0]) ? $parameters[0]->getType() : NULL;
      if (!$method->isPublic() || !$method->isStatic() || count($parameters) !== 1 || !$type instanceof \ReflectionNamedType || $type->getName() !== DataDefinitionInterface::class || preg_match('/^(set|keep)/', $method->getName())) {
        continue;
      }
      $getters[] = $method;
    }
    return $getters;
  }

  /**
   * Asks a getter, the exception it throws being its answer.
   *
   * @param \Closure $getter
   *   The getter.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return mixed
   *   Its answer, or `['thrown' => class, 'message' => message]`.
   */
  protected static function answer(\Closure $getter, DataDefinitionInterface $definition): mixed {
    try {
      return $getter($definition);
    }
    catch (\Throwable $e) {
      return ['thrown' => get_class($e), 'message' => $e->getMessage()];
    }
  }

  /**
   * Shows a value in a message.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   A short form.
   */
  protected static function shown(mixed $value): string {
    if (is_object($value)) {
      return get_class($value) . '#' . spl_object_id($value);
    }
    $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
    return is_string($json) ? substr($json, 0, 200) : get_debug_type($value);
  }

}
