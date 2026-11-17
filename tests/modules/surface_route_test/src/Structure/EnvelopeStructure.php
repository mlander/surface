<?php

declare(strict_types=1);

namespace Drupal\surface_route_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * An envelope: a required color of two, and a number of stamps.
 */
#[Structure('route_test.card.envelope')]
final class EnvelopeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('color', Schema::enum(['white', 'cream'])
      ->title('Color')
      ->default('white')
      ->required());

    $inputs->addSchema('stamps', Schema::integer()
      ->title('Stamps')
      ->default(1)
      ->minimum(1)
      ->maximum(5));
  }

}
