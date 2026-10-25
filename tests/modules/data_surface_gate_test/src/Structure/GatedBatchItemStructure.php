<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * One item of the batch: a node, which the gates ask about, and a note.
 */
#[Structure('gate_test.batch.item')]
final class GatedBatchItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('node', 'entity:node', 'Node')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
  }

}
