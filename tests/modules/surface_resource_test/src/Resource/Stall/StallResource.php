<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Stall;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\StallStorage;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * A market stall: anonymous maps, plain and holding a structure.
 *
 * `where` is a plain map holding a plain map two levels deep; `kit` is a
 * map holding a structure and a map that holds one, two levels deep:
 * each is a structure with no id, written inline.
 */
#[Resource('resource_test.stall', storage: StallStorage::class)]
#[Operation('configure', label: 'Set up the stall', permission: 'access content')]
final class StallResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))->setRequired(TRUE);

    $inputs->addMap('where', t('Where'), static function (BlueprintInterface $where): void {
      $where->add('row', 'string', t('Row'), default: 'A');
      $where->addMap('point', t('Point'), static function (BlueprintInterface $point): void {
        $point->add('lat', 'float', t('Latitude'));
        $point->add('lng', 'float', t('Longitude'));
      });
    });

    $inputs->addMap('kit', t('Kit'), static function (BlueprintInterface $kit): void {
      $kit->add('tent', 'boolean', t('Tent'), default: FALSE);
      $kit->attach('bench', BenchStructure::class)->setLabel(t('Bench'));
      $kit->addMap('spare', t('Spare'), static function (BlueprintInterface $spare): void {
        $spare->add('count', 'integer', t('How many'), default: 0);
        $spare->attach('bench', BenchStructure::class)->setLabel(t('Spare bench'));
      });
    });
    $inputs->describe('kit', description: t('What the stall brings.'));
  }

}
