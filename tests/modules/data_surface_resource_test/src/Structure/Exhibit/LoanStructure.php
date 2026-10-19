<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Exhibit;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Access\ExhibitAccess;

/**
 * Who an exhibit is lent by: attached at the exhibit's loan key.
 *
 * The same access class as the provenance, answering in its own frame,
 * and a child of its own, the insurance, whose access is always runtime.
 */
#[Structure('resource_test.loan', access: ExhibitAccess::class)]
final class LoanStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('lender', 'string', t('Lender'));
    $inputs->add('until', 'integer', t('Until'));
    $inputs->attach('insurance', InsuranceStructure::class)
      ->setLabel(t('Insurance'));
  }

}
