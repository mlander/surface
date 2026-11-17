<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * One item of the batch: a schema, which the gates ask about, and a note.
 */
#[Structure('gate_test.batch.item')]
final class GatedBatchItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('node', Schema::integer()
      ->title('Schema')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node'])
      ->required());
    $inputs->addSchema('note', Schema::string()
      ->title('Note'));
  }

}
