<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\PortableKeywords;
use PortableSchema\Builder\Schema;
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
  public function refine(string $name, Schema $key, array $values, string $who): array {
    $type = $values['entity_type'] ?? NULL;
    if (!is_string($type) || $type === '') {
      return [];
    }
    $bundles = (array) (NodeFacts::setting($key, References::TARGETS)[$type] ?? []);
    $options = array_filter(['entityTypeId' => $type, 'bundles' => array_values($bundles)]);
    return [Schema::any()->with(PortableKeywords::CHECK, [PortableKeywords::PROVIDER . References::CONSTRAINT => $options])];
  }

}
