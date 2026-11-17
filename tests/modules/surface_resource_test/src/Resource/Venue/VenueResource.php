<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Venue;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\VenueStorage;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('layout', Schema::options([
      'theatre' => t('Theatre'),
      'banquet' => t('Banquet'),
      'standing' => t('Standing'),
    ])
      ->title(t('Layout'))
      ->default('theatre')
      ->required());
    $inputs->addSchema('capacity', Schema::integer()
      ->title(t('Capacity'))
      ->default(80)
      ->minimum(1)
      ->maximum(1000)
      ->required());
    $inputs->attach('seating', SeatingStructure::class, t('Seating'), reads: [
      'layout' => 'layout',
      'guests' => 'capacity',
    ]);
  }

}
