<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * A complex definition that is no MapDataDefinition, for the hand corpus.
 *
 * Its properties are the ones set on it. The validator walks past a
 * property ruled out only where every map above it is a
 * MapDataDefinition, so a property ruled out below this one is still
 * walked.
 */
final class NotAMapDefinition extends ComplexDataDefinitionBase {

  /**
   * Creates an empty one, of the `map` data type.
   *
   * @param string $type
   *   Not read: the data type is always `map`.
   *
   * @return static
   *   The definition.
   */
  public static function create($type = 'map'): static {
    $definition = new static(['type' => 'map']);
    $definition->propertyDefinitions = [];
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    return $this->propertyDefinitions;
  }

  /**
   * Sets or removes one property.
   *
   * @param string $name
   *   The property's name.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   Its definition, or NULL to remove it.
   *
   * @return $this
   */
  public function setPropertyDefinition(string $name, ?DataDefinitionInterface $definition): static {
    if ($definition === NULL) {
      unset($this->propertyDefinitions[$name]);
    }
    else {
      $this->propertyDefinitions[$name] = $definition;
    }
    return $this;
  }

}
