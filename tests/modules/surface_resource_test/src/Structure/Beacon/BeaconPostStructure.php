<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Beacon;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * A beacon post: a second owner mixing in the same two beacons.
 *
 * Its signal chooses between them as the beacon's mode does, so its code,
 * color and pattern are declared as wide as both here too, and held to
 * the chosen one's definition.
 */
#[Structure('resource_test.beacon_post')]
final class BeaconPostStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('signal', 'string', t('Signal'))
      ->setRequired(TRUE);
    $inputs->mixInVariants('signal', from: KitVariants::class, arguments: [
      'flash',
      FlashBeaconStructure::class,
      'steady',
      SteadyBeaconStructure::class,
    ]);
  }

}
