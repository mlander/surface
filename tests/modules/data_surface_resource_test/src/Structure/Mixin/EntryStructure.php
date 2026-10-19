<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Mixin;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * An entry: a line, with the record mixed in, which mixes the timestamps in.
 */
#[Structure('resource_test.entry')]
final class EntryStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('line', 'string', t('Line'));
    $inputs->with(RecordStructure::class);
  }

}
