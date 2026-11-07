<?php

declare(strict_types=1);

namespace Drupal\surface\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What applying a stage takes, nothing, and what it answers.
 *
 * The keys of the stage's `apply` operation, which names this structure
 * with `structure:`: none, so a call sends an empty map and the call
 * itself is the write. Its output is each write apply made, or a dry run
 * would make, in order, with whether it moved what is stored and the id
 * of the thing it wrote, as text whatever the resource types it as; a
 * dry run writes nothing and answers no id.
 *
 * @internal
 */
#[Structure('surface.stage.applied',
  label: new TranslatableMarkup('Applied writes'),
  description: new TranslatableMarkup('The writes an apply made, in order.'),
)]
final class StageAppliedStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $item = StageChangesStructure::write(t('Applied write'))
      ->setPropertyDefinition('changed', DataDefinition::create('boolean')
        ->setLabel(t('Changed'))
        ->setDescription(t('Whether the write moved what is stored; false when the staged values were what is stored by then.'))
        ->setRequired(TRUE))
      ->setPropertyDefinition('id', DataDefinition::create('string')
        ->setLabel(t('Id'))
        ->setDescription(t('The id of the thing written, as text; absent for a dry run, which writes nothing.')));
    $outputs->addList('applied', $item, t('Applied writes'))
      ->setDescription(t('Each staged write, in the order apply made them, or a dry run would.'))
      ->setRequired(TRUE);
  }

}
