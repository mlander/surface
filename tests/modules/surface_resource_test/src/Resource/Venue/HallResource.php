<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Venue;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\HallStorage;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

/**
 * A hall: the same seating structure, reused under another name.
 *
 * Its chairs read the hall's arrangement as their layout, and leave the
 * optional guests port unwired. HallSeatingAlter attaches the structure a
 * second time, at its own mount, reading the hall's style and guests.
 */
#[Resource('resource_test.hall', storage: HallStorage::class)]
final class HallResource implements ResourceInterface {

  /**
   * Configures the hall, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the hall', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $layouts = [
      'theatre' => t('Theatre'),
      'banquet' => t('Banquet'),
      'standing' => t('Standing'),
    ];
    $inputs->addSchema('arrangement', Schema::options($layouts)->type('string')
      ->title(t('Arrangement'))
      ->default('banquet')
      ->required());
    $inputs->addSchema('style', Schema::options($layouts)->type('string')
      ->title(t('Overflow style'))
      ->default('theatre')
      ->required());
    $inputs->addSchema('guests', Schema::integer()
      ->title(t('Guests'))
      ->default(200)
      ->minimum(1)
      ->maximum(2000)
      ->required());
    $inputs->attach('chairs', SeatingStructure::class, t('Chairs'), reads: ['layout' => 'arrangement']);
  }

}
