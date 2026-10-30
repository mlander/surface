<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
    $inputs->mixIn(RecordStructure::class);
  }

}
