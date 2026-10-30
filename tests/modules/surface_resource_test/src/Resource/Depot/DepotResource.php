<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Depot;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Shape\Sku;
use Drupal\surface_resource_test\Storage\DepotStorage;
use Drupal\surface_resource_test\Structure\Depot\ShelfTagStructure;

/**
 * A depot: one shape at two keys, each narrowing it its own way.
 *
 * The stock code is required and at least four long; the spare code is
 * optional, at most six, with a default and words of its own. The shelf
 * tag's port reads the stock code.
 */
#[Resource('resource_test.depot', storage: DepotStorage::class)]
final class DepotResource implements ResourceInterface {

  /**
   * Configures the depot, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the depot', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    // The shape's Length is replaced by core's addConstraint(), so the key
    // restates the maximum it keeps.
    $inputs->add('sku', Sku::class, t('Stock code'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['min' => 4, 'max' => 12]);
    $inputs->add('spare', Sku::class, t('Spare code'), default: 'ZZ9')
      ->setDescription(t('Printed on the back of the shelf.'))
      ->addConstraint('Length', ['max' => 6]);
    $inputs->attach('tag', ShelfTagStructure::class, reads: ['code' => 'sku'])
      ->setLabel(t('Shelf tag'));
  }

}
