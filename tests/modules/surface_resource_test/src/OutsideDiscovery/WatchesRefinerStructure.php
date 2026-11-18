<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Broken: a refiner that says watches:, which the attribute no longer takes.
 */
#[Structure('resource_test.broken.watches_refiner')]
final class WatchesRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('first', Schema::string()
      ->title('First'));
    $inputs->add('third', Schema::string()
      ->title('Third'));
  }

  /**
   * Lists what it watches beside its parameters.
   */
  #[RefinesInput('third', watches: ['first'])]
  public static function thirdOf(string $first): Schema {
    return Schema::any();
  }

}
