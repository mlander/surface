<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('offset', Schema::integer()
      ->title(t('Offset'))
      ->description(t('How many venues to skip, in order of weight.'))
      ->default(0)
      ->minimum(0)
      ->maximum(10000));

    $inputs->add('limit', Schema::integer()
      ->title(t('Limit'))
      ->description(t('How many venues to answer at most.'))
      ->default(20)
      ->minimum(1)
      ->maximum(50));

    $inputs->add('status', Schema::bool()
      ->title(t('Enabled'))
      ->description(t('Only the venues enabled, or only those disabled; left out, every venue.')));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('items', Schema::list(Schema::object([
      'id' => Schema::string()->title(t('Machine name'))->required(),
      'label' => Schema::string()->title(t('Name'))->required(),
      'capacity' => Schema::integer()->title(t('Capacity'))->required(),
      'weight' => Schema::integer()->title(t('Weight'))->required(),
    ])->title(t('Venue')))
      ->title(t('Venues'))
      ->description(t('The page of venues, lighter first, each one the account may read.'))
      ->required());

    $outputs->add('total', Schema::integer()
      ->title(t('Total'))
      ->description(t('How many venues the filter matched that the account may read, on every page.'))
      ->required());
  }

}
