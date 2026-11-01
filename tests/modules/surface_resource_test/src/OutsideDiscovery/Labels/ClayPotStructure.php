<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Labels;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A clay pot: its #[Variant] gives no label, so its own is offered.
 *
 * Registered only by the test that says so.
 */
#[Structure('resource_test.planter.pot.clay',
  label: new TranslatableMarkup('Clay pot'),
  description: new TranslatableMarkup('A pot of fired clay, glazed or not.'),
)]
#[Variant(of: PlanterStructure::class, at: 'pot', for: 'clay')]
final class ClayPotStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('glazed', 'boolean', t('Glazed'), default: FALSE);
  }

}
