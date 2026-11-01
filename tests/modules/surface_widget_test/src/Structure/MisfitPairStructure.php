<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A pair that asks for a checkbox, which draws one value, not a map.
 */
#[Structure('widget_test.misfit_pair', widget: 'checkbox')]
final class MisfitPairStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('left', 'string', t('Left'));
  }

}
