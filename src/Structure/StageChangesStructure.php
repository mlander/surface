<?php

declare(strict_types=1);

namespace Drupal\surface\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $outputs->addSchema('items', Schema::list(self::write(t('Staged write'))
      ->properties([
        'changed' => Schema::list(Schema::string())
          ->title(t('Changed'))
          ->description(t('The JSON Pointer of each top level key the stage moved, or the empty pointer "" for a delete, which takes the whole thing; apply sends the moved keys, and makes the delete.')),
        'staged' => Schema::of('portable:timestamp')
          ->title(t('Staged'))
          ->description(t('When the first write to the thing was staged.'))
          ->required(),
      ]))
      ->title(t('Staged writes'))
      ->description(t('Every write the stage holds, in the order apply will make them; empty when it holds none.')));

    $outputs->addSchema('total', Schema::integer()
      ->title(t('Total'))
      ->description(t('How many writes the stage holds.'))
      ->required());
  }

  /**
   * Declares what names one staged write: where, through what, to what.
   *
   * Shared with what apply answers (StageAppliedStructure), which adds
   * its own properties after these.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The object's title.
   *
   * @return \PortableSchema\Builder\Schema
   *   An object of the position, the resource, the operation, the kind and
   *   the parameters, each parameter's value as text.
   */
  public static function write(TranslatableMarkup $label): Schema {
    return Schema::object([
      'position' => Schema::integer()
        ->title(t('Position'))
        ->description(t("Its place in the stage, from 1, the same for the stage's life."))
        ->required(),
      'resource' => Schema::string()->title(t('Resource'))->required(),
      'operation' => Schema::string()->title(t('Operation'))->required(),
      'kind' => Schema::string()
        ->title(t('Kind'))
        ->description(t('create, update or delete.'))
        ->required(),
      'parameters' => Schema::list(Schema::object([
        'name' => Schema::string()->title(t('Name'))->required(),
        'value' => Schema::string()->title(t('Value'))->required(),
      ])->title(t('Parameter')))
        ->title(t('Parameters'))
        ->description(t("The operation's parameters that named the thing, each value as text; for a create, the local id the stage gave it (a negative integer) or the name the caller gave; empty for a thing with no identity.")),
    ])->title($label);
  }

}
