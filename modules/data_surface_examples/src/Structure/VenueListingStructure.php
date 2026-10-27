<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 15: what a listing of the venues takes, and what it answers.
 *
 * The keys of the venue resource's list operation, which names this
 * structure with `structure:`: a page of the venues, by their weight, and
 * which of them by their status. Its outputs are the page and how many
 * venues the filter matched.
 */
#[Structure('event.venue.listing')]
final class VenueListingStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('offset', 'integer', t('Offset'), default: 0)
      ->setDescription(t('How many venues to skip, in order of weight.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 10000]);

    $inputs->add('limit', 'integer', t('Limit'), default: 20)
      ->setDescription(t('How many venues to answer at most.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);

    $inputs->add('status', 'boolean', t('Enabled'))
      ->setDescription(t('Only the venues enabled, or only those disabled; left out, every venue.'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $item = MapDataDefinition::create()
      ->setLabel(t('Venue'))
      ->setPropertyDefinition('id', DataDefinition::create('string')->setLabel(t('Machine name'))->setRequired(TRUE))
      ->setPropertyDefinition('label', DataDefinition::create('string')->setLabel(t('Name'))->setRequired(TRUE))
      ->setPropertyDefinition('capacity', DataDefinition::create('integer')->setLabel(t('Capacity'))->setRequired(TRUE))
      ->setPropertyDefinition('weight', DataDefinition::create('integer')->setLabel(t('Weight'))->setRequired(TRUE));
    $outputs->addList('items', $item, t('Venues'))
      ->setDescription(t('The page of venues, lighter first, each one the account may read.'))
      ->setRequired(TRUE);

    $outputs->add('total', 'integer', t('Total'))
      ->setDescription(t('How many venues the filter matched that the account may read, on every page.'))
      ->setRequired(TRUE);
  }

}
