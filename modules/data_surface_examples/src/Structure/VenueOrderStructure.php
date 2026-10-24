<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 15: one venue and the weight a reorder gives it.
 */
#[Structure('event.venue.order')]
final class VenueOrderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Venue'))
      ->setDescription(t('The machine name of a venue the site has.'))
      ->setRequired(TRUE);
    $inputs->add('weight', 'integer', t('Weight'))
      ->setDescription(t('Its place among the venues: lighter first.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => -1000, 'max' => 1000]);
  }

}
