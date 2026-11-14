<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Reference\References;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * Narrows an id of any entity type to the type its sibling chose.
 *
 * For the door corpus: once `entity_type` holds a type, the id gets the
 * reference constraint for that type, with the bundles the id's TARGETS
 * setting names for it, as an any-entity structure's refiner does.
 */
final class AnyTypeRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $type = $values['entity_type'] ?? NULL;
    if (!is_string($type) || $type === '') {
      return $definition;
    }
    $copy = clone $definition;
    $bundles = (array) ($definition->getSetting(References::TARGETS)[$type] ?? []);
    $options = array_filter(['entityTypeId' => $type, 'bundles' => array_values($bundles)]);
    return $copy->addConstraint(References::CONSTRAINT, $options);
  }

}
