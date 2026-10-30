<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * Wires a port the bench, at every entry, does not read.
 */
#[Structure('resource_test.broken.entry_port_unread')]
final class EntryPortUnreadStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('lights', 'boolean', from: WorkshopBenches::class);
    $inputs->attachEach('benches', BenchStructure::class, from: WorkshopBenches::class, reads: ['lit' => 'lights']);
  }

}
