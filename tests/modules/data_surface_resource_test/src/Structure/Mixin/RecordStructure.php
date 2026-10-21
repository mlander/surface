<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Mixin;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A record: a reference, with the timestamps mixed in.
 *
 * A structure that mixes another in, which the entry mixes in in turn:
 * its members, the timestamps' among them, are the entry's own keys.
 */
#[Structure('resource_test.record')]
final class RecordStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('reference', 'string', t('Reference'));
    $inputs->mixIn(TimestampsStructure::class);
  }

}
