<?php

declare(strict_types=1);

namespace Drupal\surface\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What listing a stage's writes takes, nothing, and what it answers.
 *
 * The keys of the stage's `changes` operation, which names this structure
 * with `structure:`: none. Its outputs are the writes the stage holds, in
 * the order apply makes them, each by its position, which stays the same
 * for the stage's life and is what a refusal at apply names, and how
 * many there are. A write's values are never listed: the thing's own read
 * answers them, with what that read leaves out left out.
 *
 * @internal
 */
#[Structure('surface.stage.changes',
  label: new TranslatableMarkup('Staged writes'),
  description: new TranslatableMarkup('The writes a stage holds, in the order apply makes them.'),
)]
final class StageChangesStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $item = self::write(t('Staged write'))
      ->setPropertyDefinition('changed', ListDataDefinition::create('string')
        ->setLabel(t('Changed'))
        ->setDescription(t('The JSON Pointer of each top level key the stage moved; apply sends these.')))
      ->setPropertyDefinition('staged', DataDefinition::create('timestamp')
        ->setLabel(t('Staged'))
        ->setDescription(t('When the first write to the thing was staged.'))
        ->setRequired(TRUE));
    $outputs->addList('items', $item, t('Staged writes'))
      ->setDescription(t('Every write the stage holds, in the order apply will make them; empty when it holds none.'));

    $outputs->add('total', 'integer', t('Total'))
      ->setDescription(t('How many writes the stage holds.'))
      ->setRequired(TRUE);
  }

  /**
   * Declares what names one staged write: where, through what, to what.
   *
   * Shared with what apply answers (StageAppliedStructure).
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The map's label.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   A map of the position, the resource, the operation, the kind and
   *   the parameters, each parameter's value as text.
   */
  public static function write(TranslatableMarkup $label): MapDataDefinition {
    $parameter = MapDataDefinition::create()
      ->setLabel(t('Parameter'))
      ->setPropertyDefinition('name', DataDefinition::create('string')->setLabel(t('Name'))->setRequired(TRUE))
      ->setPropertyDefinition('value', DataDefinition::create('string')->setLabel(t('Value'))->setRequired(TRUE));
    return MapDataDefinition::create()
      ->setLabel($label)
      ->setPropertyDefinition('position', DataDefinition::create('integer')
        ->setLabel(t('Position'))
        ->setDescription(t("Its place in the stage, from 1, the same for the stage's life."))
        ->setRequired(TRUE))
      ->setPropertyDefinition('resource', DataDefinition::create('string')->setLabel(t('Resource'))->setRequired(TRUE))
      ->setPropertyDefinition('operation', DataDefinition::create('string')->setLabel(t('Operation'))->setRequired(TRUE))
      ->setPropertyDefinition('kind', DataDefinition::create('string')
        ->setLabel(t('Kind'))
        ->setDescription(t('create, update or delete.'))
        ->setRequired(TRUE))
      ->setPropertyDefinition('parameters', (new ListDataDefinition([], $parameter))
        ->setLabel(t('Parameters'))
        ->setDescription(t("The operation's parameters that named the thing, each value as text; empty for a thing with no identity.")));
  }

}
