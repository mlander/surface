<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Exhibit;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Access\ExhibitAccess;

/**
 * Where an exhibit came from: mixed into the exhibit, its keys the exhibit's.
 *
 * Its access class answers for its two members in the exhibit's frame.
 */
#[Structure('resource_test.provenance', access: ExhibitAccess::class)]
final class ProvenanceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('source', 'string', t('Source'));
    $inputs->add('acquired', 'integer', t('Acquired'))
      ->addConstraint('Range', ['min' => 1800]);
  }

}
