<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Exhibit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\InsuranceAccess;

/**
 * A loan's insurance, whose access waits for the thing to be known.
 */
#[Structure('resource_test.insurance', access: InsuranceAccess::class)]
final class InsuranceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('policy', 'string', t('Policy'));
  }

}
