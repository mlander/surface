<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Broken: a refiner that says watches:, which the attribute no longer takes.
 */
#[Structure('resource_test.broken.watches_refiner')]
final class WatchesRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('first', 'string', 'First');
    $inputs->add('third', 'string', 'Third');
  }

  /**
   * Lists what it watches beside its parameters.
   */
  #[RefinesInput('third', watches: ['first'])]
  public static function thirdOf(DataDefinitionInterface $third, string $first): DataDefinitionInterface {
    return $third;
  }

}
