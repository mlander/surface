<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Workshop;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Shape\Sku;
use Drupal\data_surface_resource_test\Storage\WorkshopStorage;
use Drupal\data_surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * A workshop: keys that come from the site, one per bench it lists.
 *
 * Each bench is a structure at `benches`, a light switch at `lights` and
 * a stock code, a shape, at `codes`; the benches are the site's
 * configuration, and one of them is locked there. A closed workshop has no
 * lights to switch.
 */
#[Resource('resource_test.workshop', storage: WorkshopStorage::class)]
final class WorkshopResource implements ResourceInterface {

  /**
   * Configures the workshop, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the workshop', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('closed', 'boolean', t('Closed'), default: FALSE);
    $inputs->attachEach('benches', BenchStructure::class, from: WorkshopBenches::class)
      ->setLabel(t('Benches'));
    $inputs->addEach('lights', 'boolean', from: WorkshopBenches::class, default: FALSE)
      ->setLabel(t('Lights'))
      ->setDescription(t('Which benches have their lamp on.'));
    $inputs->addEach('codes', Sku::class, from: WorkshopBenches::class)
      ->setLabel(t('Bench codes'));
    $inputs->narrow('lights')
      ->when(Condition::equals('closed', TRUE))
      ->doesNotApply();
  }

}
