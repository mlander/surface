<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Outputs, a value and a list, with calls: OutputsPropertiesStructure's twin.
 */
#[Structure('property_test.outputs')]
final class OutputsStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('query', 'string', t('Query'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('total', 'integer', t('Total'))
      ->setDescription(t('How many matched.'))
      ->setRequired(TRUE);

    $item = MapDataDefinition::create()
      ->setPropertyDefinition('id', DataDefinition::create('string')->setLabel(t('Id'))->setRequired(TRUE))
      ->setPropertyDefinition('label', DataDefinition::create('string')->setLabel(t('Label')));
    $outputs->addList('items', $item, t('Items'))
      ->setRequired(TRUE);
  }

}
