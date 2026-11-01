<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Labels;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Shape\Sku;

/**
 * A planter: a pot its kind chooses, the soil in it, and a stock code.
 *
 * Says what it is called and what it is for. Its soil says neither, its
 * clay pot says both and no #[Variant] label, and its stone pot says
 * both and a #[Variant] label of its own. Registered only by the test
 * that says so.
 */
#[Structure('resource_test.planter',
  label: new TranslatableMarkup('Planter'),
  description: new TranslatableMarkup('A pot its kind chooses, and the soil in it.'),
)]
final class PlanterStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', Sku::class, t('Stock code'));

    $inputs->add('kind', 'string', t('Kind'), default: 'clay');

    $inputs->attachVariants('pot', by: 'kind')
      ->setLabel(t('Pot'));

    $inputs->attach('soil', SoilStructure::class)
      ->setLabel(t('Soil'));
  }

}
