<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Copies a definition of any class into one of the three a schema derives.
 *
 * A definition handed at the door (SurfaceBuilder::setDefinition(),
 * addDefinition()) of a class NodeDefinition::DERIVED does not name is
 * read through its getters into a DataDefinition, a ListDataDefinition or
 * a MapDataDefinition, at every depth, so the builder can hold its schema
 * and every reader can derive a definition from it. A complex definition
 * is a map of its properties, a list a list of its item, any other one
 * value. What a class keeps outside its definition array and its getters
 * do not give (a reference's target, a field item's field definition) is
 * not copied: such a class is not one the door reads this way.
 *
 * Built in part 32.9 for the door; which classes it is used for is the
 * wiring's (see the stream's WIRING.md): one the hand or door corpus
 * digests by its class stays definition-first.
 *
 * @internal
 */
final class PlainDefinition {

  /**
   * Copies a definition, at every depth, into one of the three classes.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition itself where it and everything it holds is of the
   *   three classes; otherwise a copy that is.
   */
  public static function of(DataDefinitionInterface $definition): DataDefinitionInterface {
    if (static::isPlain($definition)) {
      return $definition;
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $copy = new ListDataDefinition(static::array($definition, 'list'), static::of($definition->getItemDefinition()));
      return $copy;
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      $copy = new MapDataDefinition(static::array($definition, 'map'));
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $copy->setPropertyDefinition((string) $name, static::of($property));
      }
      if ($definition->getMainPropertyName() !== NULL) {
        $copy->setMainPropertyName($definition->getMainPropertyName());
      }
      return $copy;
    }
    return new DataDefinition(static::array($definition, $definition->getDataType()));
  }

  /**
   * Answers whether a definition and all it holds is of the three classes.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE where every definition at every depth is of one of them.
   */
  public static function isPlain(DataDefinitionInterface $definition): bool {
    if (!in_array($definition::class, NodeDefinition::DERIVED, TRUE)) {
      return FALSE;
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      return static::isPlain($definition->getItemDefinition());
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $property) {
        if (!static::isPlain($property)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Reads a definition's array, through its getters where it has none.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $type
   *   The data type of the copy.
   *
   * @return array
   *   The array a definition of one of the three classes holds the facts in.
   */
  private static function array(DataDefinitionInterface $definition, string $type): array {
    if ($definition instanceof DataDefinition) {
      $array = $definition->toArray();
      unset($array['class']);
      return ['type' => $type] + $array;
    }
    $array = ['type' => $type];
    foreach ([
      'label' => $definition->getLabel(),
      'description' => $definition->getDescription(),
      'required' => $definition->isRequired() ?: NULL,
      'read-only' => $definition->isReadOnly() ?: NULL,
      'computed' => $definition->isComputed() ?: NULL,
      'settings' => $definition->getSettings() ?: NULL,
      'constraints' => $definition->getConstraints() ?: NULL,
    ] as $key => $value) {
      if ($value !== NULL) {
        $array[$key] = $value;
      }
    }
    return $array;
  }

}
