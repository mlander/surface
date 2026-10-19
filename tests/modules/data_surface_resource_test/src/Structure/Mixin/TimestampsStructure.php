<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Mixin;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * When a thing was created and last changed: shared members, mixed in.
 *
 * An ordinary structure: the journal mixes it in, its two keys the
 * journal's own; the ledger and the journal's archive attach it, under a
 * key. Changed is at least created, a coded refiner, since a declared
 * rule compares a key with a value and not with another key; and once
 * the created time is set the changed time is required too, a rule
 * declared as data.
 *
 * @see docs/resources.md#mixins
 */
#[Structure('resource_test.timestamps')]
final class TimestampsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('created', 'integer', t('Created'))
      ->addConstraint('Range', ['min' => 0]);
    $inputs->add('changed', 'integer', t('Changed'))
      ->addConstraint('Range', ['min' => 0]);
    $inputs->narrow('changed')
      ->when(Condition::present('created'))
      ->require()
      ->describe(t('Required once the created time is set.'));
  }

  /**
   * Changed is never before created.
   */
  #[RefinesInput('changed')]
  public static function changedSinceCreated(DataDefinitionInterface $changed, int $created): DataDefinitionInterface {
    if (!$changed instanceof DataDefinition) {
      return $changed;
    }
    return $changed->addConstraint('Range', ['min' => $created]);
  }

}
