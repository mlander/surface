<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Reference\References;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * Narrows a reference to the page bundle when the gate says `narrow`.
 *
 * For the door corpus: a copy of the key, its reference constraint
 * naming the page bundle alone.
 */
final class BundleNarrowingRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if (($values['gate'] ?? NULL) !== 'narrow') {
      return $definition;
    }
    $copy = clone $definition;
    $options = $copy->getConstraints()[References::CONSTRAINT] ?? [];
    return $copy->addConstraint(References::CONSTRAINT, ['bundles' => ['page']] + $options);
  }

}
