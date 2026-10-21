<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Venue;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\VenueStorage;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * A venue, seated: the seating structure attached under one name.
 *
 * Both of the structure's ports are wired: its layout to the venue's own,
 * its guests to the venue's capacity.
 */
#[Resource('resource_test.venue', storage: VenueStorage::class)]
final class VenueResource implements ResourceInterface {

  /**
   * Configures the venue, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the venue', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', t('Layout'), default: 'theatre')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'theatre' => t('Theatre'),
          'banquet' => t('Banquet'),
          'standing' => t('Standing'),
        ],
      ]);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 80)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'layout', 'guests' => 'capacity'])
      ->setLabel(t('Seating'));
  }

}
