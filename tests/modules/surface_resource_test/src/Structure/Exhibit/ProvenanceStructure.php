<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Exhibit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\ExhibitAccess;

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
