<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Attaches the pair that asks for a checkbox: refused when built.
 */
#[Structure('widget_test.misfit_holder')]
final class MisfitHolderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('pair', MisfitPairStructure::class);
  }

}
