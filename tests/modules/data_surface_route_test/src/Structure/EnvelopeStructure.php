<?php

declare(strict_types=1);

namespace Drupal\data_surface_route_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * An envelope: a required color of two, and a number of stamps.
 */
#[Structure('route_test.card.envelope')]
final class EnvelopeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('color', 'string', 'Color', default: 'white')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['white', 'cream']]);

    $inputs->add('stamps', 'integer', 'Stamps', default: 1)
      ->addConstraint('Range', ['min' => 1, 'max' => 5]);
  }

}
