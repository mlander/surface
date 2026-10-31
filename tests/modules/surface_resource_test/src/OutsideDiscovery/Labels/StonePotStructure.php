<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Labels;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A stone pot: its #[Variant] label is the choice's word, over its own.
 *
 * Registered only by the test that says so.
 */
#[Structure('resource_test.planter.pot.stone',
  label: new TranslatableMarkup('Stone pot'),
)]
#[Variant(of: PlanterStructure::class, at: 'pot', for: 'stone', label: new TranslatableMarkup('Stone'))]
final class StonePotStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', t('Weight'));
  }

}
